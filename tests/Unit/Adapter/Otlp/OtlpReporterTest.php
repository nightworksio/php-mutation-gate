<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Otlp\OtlpReporter;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\OtlpPost;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The reporter in this environment, posting to these answers, with these options.
 *
 * @param list<MockResponse> $answers
 */
function otlpReporter(Variables $environment, array $answers, string $options = '{}'): OtlpReporter|Invalid
{
    return OtlpReporter::inEnvironment(Configs::options($options), $environment, new MockHttpClient($answers), new StoppedClock('2026-09-30T12:00:00Z'));
}

/** What the reporter answers of this verdict; not written where it could not be made. */
function otlpReport(OtlpReporter|Invalid $reporter, Verdict $verdict): Written|NotWritten
{
    return $reporter instanceof OtlpReporter ? $reporter->report($verdict) : NotWritten::because('invalid');
}

/** The headers a mock answer was requested with, one to a line. */
function otlpHeaders(MockResponse $answer): string
{
    $headers = $answer->getRequestOptions()['headers'];

    return is_array($headers) ? implode("\n", array_filter($headers, is_string(...))) : '';
}

$github = Variables::of([
    'GITHUB_ACTIONS' => 'true',
    'GITHUB_REPOSITORY' => 'octo/gate',
    'GITHUB_REF' => 'refs/heads/main',
    'GITHUB_SHA' => '5eeca8f',
    'GITHUB_RUN_ID' => '7',
    'GITHUB_WORKFLOW' => 'mutation',
    'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://otel.example/',
    'OTEL_EXPORTER_OTLP_HEADERS' => 'api-key=secret',
]);

it('posts the run\'s trace and the verdict\'s metrics to the endpoint, with OpenTelemetry\'s headers, within 5 seconds', function () use ($github): void {
    $traces = new MockResponse('{}');
    $metrics = new MockResponse('{}');
    $sent = otlpReport(otlpReporter($github, [$traces, $metrics]), Verdicts::named('accounted'));
    $body = $traces->getRequestOptions()['body'];

    expect($sent)->toEqual(Written::to('https://otel.example'))
        ->and($traces->getRequestUrl())->toBe('https://otel.example/v1/traces')
        ->and($metrics->getRequestUrl())->toBe('https://otel.example/v1/metrics')
        ->and(otlpHeaders($traces))->toContain('api-key: secret')
        ->and(otlpHeaders($metrics))->toContain('Content-Type: application/json')
        ->and($traces->getRequestOptions()['max_duration'])->toBe(5.0)
        ->and($traces->getRequestOptions()['max_redirects'])->toBe(0)
        ->and(Decoded::at(is_string($body) ? $body : '', 'resourceSpans', 0, 'scopeSpans', 0, 'spans', 0, 'attributes'))->toBe([
            ['key' => 'vcs.ref.head.name', 'value' => ['stringValue' => 'main']],
            ['key' => 'vcs.ref.head.revision', 'value' => ['stringValue' => '5eeca8f']],
            ['key' => 'cicd.pipeline.name', 'value' => ['stringValue' => 'mutation']],
            ['key' => 'cicd.pipeline.run.id', 'value' => ['stringValue' => 'github:12345/1']],
            ['key' => 'mutation_gate.mode', 'value' => ['stringValue' => 'change']],
        ]);
});

it('posts only metrics for a run the flows did not time, and says nothing of a CI it cannot read', function (): void {
    $metrics = new MockResponse('{}');
    $sent = otlpReport(otlpReporter(Variables::of([]), [$metrics]), Verdicts::passing());

    expect($sent)->toEqual(Written::to('http://localhost:4318'))
        ->and($metrics->getRequestUrl())->toBe('http://localhost:4318/v1/metrics');
});

it('names the runner that judged the run on every span', function () use ($github): void {
    $traces = new MockResponse('{}');
    $account = Verdicts::account();
    $timings = $account->timings();
    $runner = Identity::of('infection', Versions::of(Version::of('infection/infection', '0.29.0', 'abc123')), Digest::of('php'));
    $verdict = Verdicts::passing()->withAccount($timings instanceof RunTimings ? $account->withTimings($timings->ranBy($runner)) : $account);
    otlpReport(otlpReporter($github, [$traces, new MockResponse('{}')]), $verdict);
    $body = $traces->getRequestOptions()['body'];

    expect(Decoded::at(is_string($body) ? $body : '', 'resourceSpans', 0, 'scopeSpans', 0, 'spans', 7, 'attributes'))
        ->toContain(['key' => 'mutation_gate.runner', 'value' => ['stringValue' => 'infection']]);
});

it('says the full mode of a run with no new code, and leaves out a pipeline the CI does not name', function (): void {
    $traces = new MockResponse('{}');
    $environment = Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_REF' => 'refs/heads/main', 'GITHUB_SHA' => 'abc']);
    otlpReport(otlpReporter($environment, [$traces, new MockResponse('{}')]), Verdicts::passing()->withAccount(Verdicts::account()));
    $body = $traces->getRequestOptions()['body'];
    $attributes = Decoded::at(is_string($body) ? $body : '', 'resourceSpans', 0, 'scopeSpans', 0, 'spans', 0, 'attributes');

    expect($attributes)->toContain(['key' => 'mutation_gate.mode', 'value' => ['stringValue' => 'full']])
        ->and(json_encode($attributes))->not->toContain('cicd.pipeline.name');
});

it('takes the endpoint its options name over OpenTelemetry\'s', function () use ($github): void {
    $metrics = new MockResponse('{}');
    otlpReport(otlpReporter($github, [$metrics], '{"endpoint": "https://mine.example"}'), Verdicts::passing());

    expect($metrics->getRequestUrl())->toBe('https://mine.example/v1/metrics');
});

it('keeps OpenTelemetry\'s headers from an endpoint its options name elsewhere, and says so', function () use ($github): void {
    $traces = new MockResponse('{}');
    $metrics = new MockResponse('{}');
    $sent = otlpReport(otlpReporter($github, [$traces, $metrics], '{"endpoint": "https://mine.example"}'), Verdicts::named('accounted'));

    expect($sent)->toEqual(Written::noting(
        'https://mine.example',
        "OTEL_EXPORTER_OTLP_HEADERS go only to the endpoint OTEL_EXPORTER_OTLP_ENDPOINT names,\nand with.endpoint names another.",
    ))
        ->and(otlpHeaders($traces))->not->toContain('api-key')
        ->and(otlpHeaders($metrics))->not->toContain('api-key');
});

it('sends OpenTelemetry\'s headers to an endpoint its options name at the same scheme, host and port', function () use ($github): void {
    $metrics = new MockResponse('{}');
    $sent = otlpReport(otlpReporter($github, [$metrics], '{"endpoint": "https://otel.example/collector"}'), Verdicts::passing());

    expect($sent)->toEqual(Written::to('https://otel.example'))
        ->and(otlpHeaders($metrics))->toContain('api-key: secret');
});

it('says nothing of headers where OpenTelemetry\'s variables set none', function (): void {
    $sent = otlpReport(
        otlpReporter(Variables::of(['OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://otel.example']), [new MockResponse('{}')], '{"endpoint": "https://mine.example"}'),
        Verdicts::passing(),
    );

    expect($sent)->toEqual(Written::to('https://mine.example'));
});

it('says not written, tries nothing again, where the collector refuses or cannot be reached', function () use ($github): void {
    $refused = otlpReport(otlpReporter($github, [new MockResponse('bad', ['http_code' => 400]), new MockResponse('{}')]), Verdicts::named('accounted'));
    $unreached = otlpReport(otlpReporter($github, [new MockResponse('', ['error' => 'Could not resolve host'])]), Verdicts::passing());

    expect($refused)->toEqual(NotWritten::because('https://otel.example answered 400: bad'))
        ->and($unreached instanceof NotWritten ? $unreached->why() : '')->toStartWith('https://otel.example could not be reached: ');
});

it('refuses an endpoint that is not text', function (): void {
    expect(otlpReporter(Variables::of([]), [], '{"endpoint": 3}'))->toEqual(Invalid::because(Problem::at('endpoint', 'expected text, got 3')));
});

it('reads its own environment and posts over the network', function (): void {
    $reporter = Environment::during(
        ['OTEL_EXPORTER_OTLP_ENDPOINT' => null],
        static fn(): OtlpReporter|Invalid => OtlpReporter::configured(Configs::options('{"endpoint": "http://127.0.0.1:9"}'), new StoppedClock('2026-09-30T12:00:00Z')),
    );

    expect($reporter)->toBeInstanceOf(OtlpReporter::class);
});

it('names no ref or commit on the spans of a CI it cannot read', function (): void {
    $traces = new MockResponse('{}');
    otlpReport(otlpReporter(Variables::of([]), [$traces, new MockResponse('{}')]), Verdicts::named('accounted'));
    $body = $traces->getRequestOptions()['body'];

    expect(Decoded::at(is_string($body) ? $body : '', 'resourceSpans', 0, 'scopeSpans', 0, 'spans', 0, 'attributes'))->toBe([
        ['key' => 'cicd.pipeline.run.id', 'value' => ['stringValue' => 'github:12345/1']],
        ['key' => 'mutation_gate.mode', 'value' => ['stringValue' => 'change']],
    ]);
});

it('names an endpoint by its scheme, host and port alone, so a key in it reaches no log', function (): void {
    $endpoint = Variables::of(['OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://user:s3cr3t@otel.example:4318/otlp?api-key=k3y']);
    $traces = new MockResponse('{}');
    $metrics = new MockResponse('{}');
    $sent = otlpReport(otlpReporter($endpoint, [$traces, $metrics]), Verdicts::named('accounted'));
    $refused = otlpReport(otlpReporter($endpoint, [new MockResponse('no', ['http_code' => 403])]), Verdicts::passing());
    $unreached = otlpReport(otlpReporter($endpoint, [new MockResponse('', ['error' => 'Failed for "https://user:s3cr3t@otel.example:4318/otlp/v1/metrics?api-key=k3y"'])]), Verdicts::passing());
    $said = implode("\n", [
        $sent instanceof Written ? $sent->where() : '',
        $refused instanceof NotWritten ? $refused->why() : '',
        $unreached instanceof NotWritten ? $unreached->why() : '',
    ]);

    expect($sent)->toEqual(Written::to('https://otel.example:4318'))
        ->and($refused)->toEqual(NotWritten::because('https://otel.example:4318 answered 403: no'))
        ->and($traces->getRequestUrl())->toBe('https://user:s3cr3t@otel.example:4318/otlp/v1/traces?api-key=k3y')
        ->and($said)->not->toContain('s3cr3t')
        ->and($said)->not->toContain('k3y')
        ->and($said)->not->toContain('/otlp');
});

it('exports the trace and the metrics a run left as they were left, and says where the trace was refused', function () use ($github): void {
    $traces = new MockResponse('{}');
    $metrics = new MockResponse('{}');
    $reporter = otlpReporter($github, [$traces, $metrics]);
    $sent = $reporter instanceof OtlpReporter ? $reporter->exported(OtlpPost::of('{"resourceSpans": []}', '{"resourceMetrics": []}')) : 'invalid';
    $refused = otlpReporter($github, [new MockResponse('bad', ['http_code' => 400]), new MockResponse('{}')]);

    expect($sent)->toEqual(Written::to('https://otel.example'))
        ->and([$traces->getRequestUrl(), $traces->getRequestOptions()['body']])->toBe(['https://otel.example/v1/traces', '{"resourceSpans": []}'])
        ->and([$metrics->getRequestUrl(), $metrics->getRequestOptions()['body']])->toBe(['https://otel.example/v1/metrics', '{"resourceMetrics": []}'])
        ->and($refused instanceof OtlpReporter ? $refused->exported(OtlpPost::of('{}', '{}')) : 'invalid')
        ->toEqual(NotWritten::because('https://otel.example answered 400: bad'));
});

it('leaves the trace and the metrics it would export for deliver, sending nothing', function () use ($github): void {
    $client = new MockHttpClient([]);
    $reporter = OtlpReporter::inEnvironment(Configs::options('{}'), $github, $client, new StoppedClock('2026-09-30T12:00:00Z'));
    $timed = $reporter instanceof OtlpReporter ? $reporter->deferred(Verdicts::named('accounted'), Delivery::none()) : 'invalid';
    $untimed = $reporter instanceof OtlpReporter ? $reporter->deferred(Verdicts::passing(), Delivery::none()) : 'invalid';
    $timedOtlp = $timed instanceof Delivery ? $timed->otlp() : $timed;
    $untimedOtlp = $untimed instanceof Delivery ? $untimed->otlp() : $untimed;

    expect($client->getRequestsCount())->toBe(0)
        ->and($timedOtlp instanceof OtlpPost && is_string($timedOtlp->traces())
            ? Decoded::at($timedOtlp->traces(), 'resourceSpans', 0, 'scopeSpans', 0, 'spans', 0, 'attributes', 0, 'key')
            : $timedOtlp)->toBe('vcs.ref.head.name')
        ->and($untimedOtlp instanceof OtlpPost ? $untimedOtlp->traces() : $untimedOtlp)->toEqual(NotGiven::value())
        ->and($untimedOtlp instanceof OtlpPost ? Decoded::at($untimedOtlp->metrics(), 'resourceMetrics', 0, 'scopeMetrics', 0, 'scope', 'name') : $untimedOtlp)
        ->toBe('mutation-gate');
});
