<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use function array_map;

use Closure;

use function getenv;

use NightWorksIO\MutationGate\Adapter\Alert\AlertReporter;
use NightWorksIO\MutationGate\Adapter\Alert\Channel;
use NightWorksIO\MutationGate\Adapter\Alert\Delivery as AlertDelivery;
use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Adapter\Otlp\OtelEnvironment;
use NightWorksIO\MutationGate\Adapter\Otlp\OtlpReporter;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Delivery\AlertPost;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\LedgerPost;
use NightWorksIO\MutationGate\Core\Delivery\OtlpPost;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\GateSecret;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;
use Psr\Clock\ClockInterface;

use function sprintf;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What `deliver` does with a delivery (ADR-0007 decision 5): it writes the
 * ledger to the store its own environment locates through the
 * `MUTATION_GATE_STORE` variables, and sends the comment, each alert and the
 * OTLP export where its own environment says, with the credentials its own
 * environment holds. Nothing in the delivery names a destination, a store or
 * a variable: the comment goes to `GITHUB_REPOSITORY`'s pull request the
 * event names, each alert to its channel's `MUTATION_GATE_*_URL`, signed with
 * `MUTATION_GATE_WEBHOOK_SECRET`, and the export to
 * `OTEL_EXPORTER_OTLP_ENDPOINT` with its headers.
 */
final readonly class Sending
{
    private const string NO_STORE = 'The ledger is not written: %s';

    private const string NO_LEDGER = 'The ledger is not written, since it cannot be read: %s';

    private const string OTHER_SCOPE
        = 'The ledger is not written: the delivery holds %s\'s, and this run writes %s\'s alone.';

    /** @param array<string, string> $environment */
    public function __construct(
        private array $environment,
        private LocatedStore $stores,
        private HttpClientInterface $client,
        private AlertDelivery $alerts,
        private ClockInterface $clock,
    ) {
    }

    /** `deliver` in this process's environment, with the gate's own adapters alone, over the network. */
    public static function online(): self
    {
        $clock = new SystemClock();

        return new self(
            getenv(),
            LocatedStore::online(),
            HttpClient::create(),
            AlertDelivery::online($clock),
            $clock,
        );
    }

    /**
     * What each part of the delivery came to, and the exit code: a trusted run's ledger left unwritten fails the
     * job. The ledger is written only on a trusted run of the default branch, and only for that branch's scope;
     * elsewhere it is never read, and no store is built.
     *
     * @param Closure(): (Ledger|CannotJudge|TooLarge) $ledger the ledger beside the delivery, read when written
     */
    public function sent(Delivery $delivery, Scope|NotWritten $trusted, Closure $ledger): Sent
    {
        $post = $delivery->ledger();
        $stored = $post instanceof LedgerPost ? $this->stored($post, $trusted, $ledger) : NotGiven::value();
        $comment = $delivery->comment();
        $otlp = $delivery->otlp();
        $said = [
            ...$stored instanceof NotGiven ? [] : [$stored],
            ...$comment instanceof NotGiven ? [] : [$this->commented($comment)],
            ...array_map($this->alerted(...), $delivery->alerts()),
            ...$otlp instanceof OtlpPost ? [$this->exported($otlp)] : [],
        ];

        return Sent::of(
            array_map(
                static fn(Written|NotWritten $one): string => $one instanceof Written ? $one->said() : $one->why(),
                $said,
            ),
            $trusted instanceof Scope && $stored instanceof NotWritten ? ExitCode::CannotJudge : ExitCode::Passed,
        );
    }

    /** @param Closure(): (Ledger|CannotJudge|TooLarge) $ledger */
    private function stored(LedgerPost $post, Scope|NotWritten $trusted, Closure $ledger): Written|NotWritten
    {
        if ($trusted instanceof NotWritten) {
            return $trusted;
        }

        return $post->scope()->equals($trusted)
            ? $this->written($trusted, $ledger)
            : NotWritten::because(sprintf(self::OTHER_SCOPE, $post->scope()->ref(), $trusted->ref()));
    }

    /**
     * The ledger, written to the store its own environment locates for the default branch's scope; read only once
     * that store is built.
     *
     * @param Closure(): (Ledger|CannotJudge|TooLarge) $ledger
     */
    private function written(Scope $defaultBranch, Closure $ledger): Written|NotWritten
    {
        $store = $this->stores->forDefaultBranch($defaultBranch);
        $read = $store instanceof ProofStore ? $ledger() : $store;

        return match (true) {
            ! $store instanceof ProofStore => NotWritten::because(sprintf(self::NO_STORE, $store->why())),
            $read instanceof Ledger => $store->write($defaultBranch, $read),
            default => NotWritten::because(sprintf(self::NO_LEDGER, $read->why())),
        };
    }

    private function commented(string $markdown): Written|NotWritten
    {
        return PullRequestComment::fromEnvironment($this->environment, $this->client, '')->write($markdown);
    }

    private function alerted(AlertPost $alert): Written|NotWritten
    {
        $channel = Channel::of($alert->channel());

        return $channel instanceof Channel
            ? AlertReporter::to(
                $channel,
                Variables::of($this->environment),
                $this->alerts,
                $this->clock,
                $channel->urlEnv(),
                GateSecret::WebhookSecret->value,
            )->posted($alert->body())
            : NotWritten::because(sprintf('%s sends no alert.', $alert->channel()->value));
    }

    private function exported(OtlpPost $otlp): Written|NotWritten
    {
        $environment = Variables::of($this->environment);

        return OtlpReporter::to(
            $environment,
            $this->client,
            $this->clock,
            OtelEnvironment::of($environment)->endpoint(),
        )->exported($otlp);
    }
}
