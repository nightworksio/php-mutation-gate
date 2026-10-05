<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Otlp;

use function count;
use function explode;
use function getenv;

use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Delivery\Deferring;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\OtlpPost;
use NightWorksIO\MutationGate\Core\Http\Origin;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Telemetry\Metrics;
use NightWorksIO\MutationGate\Core\Telemetry\Trace;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;
use Psr\Clock\ClockInterface;

use function rtrim;
use function sprintf;
use function str_contains;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The reporter `otlp`: the run's spans and the verdict's metrics as
 * OTLP/HTTP JSON, posted to `/v1/traces` and `/v1/metrics` under the
 * endpoint `with.endpoint` or OpenTelemetry's own variables name. Each post
 * takes at most 5 seconds and is not tried again; a failed one is *not
 * written* (ADR-0016, decisions 14 to 17). `OTEL_EXPORTER_OTLP_HEADERS`,
 * which may hold a collector's key, go only to the endpoint
 * `OTEL_EXPORTER_OTLP_ENDPOINT` names.
 */
final readonly class OtlpReporter implements Deferring, Reporter
{
    /** What a report says where the environment's headers stayed home. */
    private const string NO_HEADERS = <<<'SAID'
        OTEL_EXPORTER_OTLP_HEADERS go only to the endpoint OTEL_EXPORTER_OTLP_ENDPOINT names,
        and with.endpoint names another.
        SAID;

    /** The longest one post may take, in seconds. */
    private const float TIMEOUT = 5.0;


    private function __construct(
        private HttpClientInterface $client,
        private ClockInterface $clock,
        private Variables $environment,
        private string $endpoint,
    ) {
    }

    /** The reporter reading this environment, posting with this client, its `with.endpoint` first. */
    public static function inEnvironment(
        Options $options,
        Variables $environment,
        HttpClientInterface $client,
        ClockInterface $clock,
    ): self|Invalid {
        $endpoint = $options->text(Key::of('endpoint'));

        return match (true) {
            $endpoint instanceof Problem => Invalid::because($endpoint),
            $endpoint instanceof NotGiven => self::to(
                $environment,
                $client,
                $clock,
                OtelEnvironment::of($environment)->endpoint(),
            ),
            default => self::to($environment, $client, $clock, $endpoint),
        };
    }

    /** The reporter reading this environment, posting with this client under this endpoint. */
    public static function to(
        Variables $environment,
        HttpClientInterface $client,
        ClockInterface $clock,
        string $endpoint,
    ): self {
        return new self($client, $clock, $environment, $endpoint);
    }

    /** The reporter in this process's environment, posting over the network. */
    public static function configured(Options $options, ClockInterface $clock): self|Invalid
    {
        return self::inEnvironment($options, Variables::of(getenv()), HttpClient::create(), $clock);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $export = $this->export($verdict);
        $json = $export->traces();
        $traces = $json instanceof NotGiven
            ? Written::to(Origin::of($this->endpoint))
            : $this->post('/v1/traces', $json);
        $metrics = $this->post('/v1/metrics', $export->metrics());

        return match (true) {
            $traces instanceof NotWritten => $traces,
            $metrics instanceof Written && $this->withholdsHeaders()
                => Written::noting($metrics->where(), self::NO_HEADERS),
            default => $metrics,
        };
    }

    /**
     * A delivery, with the trace and the metrics this reporter would export of the verdict now, for `deliver` to
     * export with the headers its own environment holds (ADR-0007 decision 5).
     */
    public function deferred(Verdict $verdict, Delivery $delivery): Delivery
    {
        return $delivery->withOtlp($this->export($verdict));
    }

    /**
     * The trace, where there is one, and the metrics a run left for `deliver`, posted as `report` posts its own
     * (ADR-0007 decision 5).
     */
    public function exported(OtlpPost $otlp): Written|NotWritten
    {
        $traces = $otlp->traces();
        $sent = $traces instanceof NotGiven
            ? Written::to(Origin::of($this->endpoint))
            : $this->post('/v1/traces', $traces);
        $metrics = $this->post('/v1/metrics', $otlp->metrics());

        return $sent instanceof NotWritten ? $sent : $metrics;
    }

    /**
     * Whether the environment's headers stay home: they go only to the endpoint
     * `OTEL_EXPORTER_OTLP_ENDPOINT` names, by its scheme, host and port, and a
     * config's own `with.endpoint` may name another.
     */
    private function withholdsHeaders(): bool
    {
        $otel = OtelEnvironment::of($this->environment);

        return $otel->headers() !== [] && Origin::of($this->endpoint) !== Origin::of($otel->endpoint());
    }

    /** The verdict's trace, for a timed run, and its metrics, as OTLP/HTTP JSON. */
    private function export(Verdict $verdict): OtlpPost
    {
        $resource = OtelEnvironment::of($this->environment)->resource();
        $timings = $verdict->account()->timings();

        return OtlpPost::of(
            $timings instanceof RunTimings ? $this->traces($verdict, $timings, $resource) : NotGiven::value(),
            OtlpJson::metrics(Metrics::of($verdict), $resource, Instant::at($this->clock->now())),
        );
    }

    /** @param array<string, string> $resource */
    private function traces(Verdict $verdict, RunTimings $timings, array $resource): string
    {
        return OtlpJson::traces(
            $timings->traceId(),
            Trace::spans($timings, $this->attributes($verdict, $timings)),
            $resource,
        );
    }

    /**
     * What every span says of its run: the ref and commit, the CI run and its pipeline, the runner, and the mode.
     *
     * @return array<string, string>
     */
    private function attributes(Verdict $verdict, RunTimings $timings): array
    {
        $run = CiRun::read($this->environment);
        $known = $run instanceof CiRun ? [
            'vcs.ref.head.name' => $run->refName(),
            'vcs.ref.head.revision' => $run->commit(),
            ...$run->pipeline() === '' ? [] : ['cicd.pipeline.name' => $run->pipeline()],
        ] : [];

        $runner = $timings->runner();

        return [
            ...$known,
            'cicd.pipeline.run.id' => $timings->run(),
            ...$runner instanceof Identity ? ['mutation_gate.runner' => $runner->runner()] : [],
            'mutation_gate.mode' => count($verdict->sets()->newCode()) > 0 ? 'change' : 'full',
        ];
    }

    private function post(string $path, string $body): Written|NotWritten
    {
        $url = $this->signal($this->endpoint, $path);
        $to = Origin::of($this->endpoint);

        try {
            $response = $this->client->request('POST', $url, [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    ...$this->withholdsHeaders() ? [] : OtelEnvironment::of($this->environment)->headers(),
                ],
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
            ]);
            $reply = Reply::of($response->getStatusCode(), '', $response->getContent(throw: false));

            return $reply->isAccepted() ? Written::to($to) : $reply->refusedBy($to);
        } catch (ExceptionInterface $unreached) {
            return Reply::unreached($to, $unreached->getMessage());
        }
    }

    /** The URL of one signal under the endpoint: its path added before any query the endpoint holds. */
    private function signal(string $endpoint, string $path): string
    {
        [$base, $query] = str_contains($endpoint, '?') ? explode('?', $endpoint, 2) : [$endpoint, ''];

        return sprintf('%s%s%s', rtrim($base, '/'), $path, $query === '' ? '' : sprintf('?%s', $query));
    }
}
