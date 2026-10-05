<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use function array_any;
use function array_filter;
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
use NightWorksIO\MutationGate\Core\Delivery\KeptPost;
use NightWorksIO\MutationGate\Core\Delivery\LedgerPost;
use NightWorksIO\MutationGate\Core\Delivery\OtlpPost;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
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
    /** Why a file beside the delivery cannot be read: there is none. */
    public const string MISSING = '%s is missing.';
    private const string NO_STORE = 'The ledger is not written: %s';

    private const string NO_LEDGER = 'The ledger is not written, since it cannot be read: %s';

    private const string OTHER_SCOPE
        = 'The ledger is not written: the delivery holds %s\'s, and this run writes %s\'s alone.';

    private const string NOT_KEPT = 'The %s is not kept: %s';

    /** Why no store is built: the delivery writes nothing to the default branch's scope on this run. */
    private const string NOTHING_TO_WRITE = 'The delivery writes nothing to the store on this run.';

    private const string UNREAD_KEPT = 'The %s is not kept, since it cannot be read: %s';

    private const string OTHER_SCOPE_KEPT
        = 'The %s is not kept: the delivery holds %s\'s, and this run writes %s\'s alone.';

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
     * What each part of the delivery came to, and the exit code: a trusted run's ledger, or an object to keep
     * beside it, left unwritten fails the job. Each is written only on a trusted run of the default branch, and
     * only for that branch's scope; elsewhere none is read, and no store is built.
     *
     * @param Closure(): (Ledger|CannotJudge|TooLarge)                   $ledger the ledger beside the delivery, read
     *                                                                            when written
     * @param Closure(Companion): (Contents|CannotJudge|TooLarge)|NotGiven $kept   each object beside it, read when
     *                                                                            kept
     */
    public function sent(
        Delivery $delivery,
        Scope|NotWritten $trusted,
        Closure $ledger,
        Closure|NotGiven $kept = new NotGiven(),
    ): Sent {
        $post = $delivery->ledger();
        $store = $this->storeFor($delivery, $trusted);
        $stored = $post instanceof LedgerPost ? $this->stored($post, $trusted, $store, $ledger) : NotGiven::value();
        $keeping = $trusted instanceof Scope ? $this->keptAll($delivery->kept(), $trusted, $store, $kept) : [];
        $comment = $delivery->comment();
        $otlp = $delivery->otlp();
        $said = [
            ...$stored instanceof NotGiven ? [] : [$stored],
            ...$keeping,
            ...$comment instanceof NotGiven ? [] : [$this->commented($comment)],
            ...array_map($this->alerted(...), $delivery->alerts()),
            ...$otlp instanceof OtlpPost ? [$this->exported($otlp)] : [],
        ];
        $unwritten = array_filter([$stored, ...$keeping], static fn(object $one): bool => $one instanceof NotWritten);

        return Sent::of(
            array_map(
                static fn(Written|NotWritten $one): string => $one instanceof Written ? $one->said() : $one->why(),
                $said,
            ),
            $trusted instanceof Scope && $unwritten !== [] ? ExitCode::CannotJudge : ExitCode::Passed,
        );
    }

    /**
     * The store its own environment locates for the default branch, built once, and only where the delivery holds
     * something to write there on a trusted run; else why none is built.
     */
    private function storeFor(Delivery $delivery, Scope|NotWritten $trusted): ProofStore|NotWritten|CannotJudge
    {
        $post = $delivery->ledger();
        $scopes = [
            ...$post instanceof LedgerPost ? [$post->scope()] : [],
            ...array_map(static fn(KeptPost $kept): Scope => $kept->scope(), $delivery->kept()),
        ];
        $writes = $trusted instanceof Scope
            && array_any($scopes, static fn(Scope $scope): bool => $scope->equals($trusted));

        return $writes ? $this->stores->forDefaultBranch($trusted) : NotWritten::because(self::NOTHING_TO_WRITE);
    }

    /**
     * Each object the delivery keeps beside a ledger, kept in the store for the default branch's scope alone.
     *
     * @param  list<KeptPost>                                         $posts
     * @param  Closure(Companion): (Contents|CannotJudge|TooLarge)|NotGiven $kept
     * @return list<Written|NotWritten>
     */
    private function keptAll(
        array $posts,
        Scope $defaultBranch,
        ProofStore|NotWritten|CannotJudge $store,
        Closure|NotGiven $kept,
    ): array {
        $said = [];

        foreach ($posts as $post) {
            $named = $post->companion()->named();
            $said[] = $post->scope()->equals($defaultBranch)
                ? $this->keptOne($post->companion(), $defaultBranch, $store, $kept)
                : NotWritten::because(
                    sprintf(self::OTHER_SCOPE_KEPT, $named, $post->scope()->ref(), $defaultBranch->ref()),
                );
        }

        return $said;
    }

    /** @param Closure(Companion): (Contents|CannotJudge|TooLarge)|NotGiven $kept */
    private function keptOne(
        Companion $companion,
        Scope $defaultBranch,
        ProofStore|NotWritten|CannotJudge $store,
        Closure|NotGiven $kept,
    ): Written|NotWritten {
        $read = match (true) {
            ! $store instanceof ProofStore => $store,
            $kept instanceof NotGiven => CannotJudge::because(sprintf(self::MISSING, $companion->value)),
            default => $kept($companion),
        };

        return match (true) {
            ! $store instanceof ProofStore
                => NotWritten::because(sprintf(self::NOT_KEPT, $companion->named(), $store->why())),
            $read instanceof Contents => $store->keep($defaultBranch, $companion, $read),
            default => NotWritten::because(sprintf(self::UNREAD_KEPT, $companion->named(), $read->why())),
        };
    }

    /** @param Closure(): (Ledger|CannotJudge|TooLarge) $ledger */
    private function stored(
        LedgerPost $post,
        Scope|NotWritten $trusted,
        ProofStore|NotWritten|CannotJudge $store,
        Closure $ledger,
    ): Written|NotWritten {
        if ($trusted instanceof NotWritten) {
            return $trusted;
        }

        return $post->scope()->equals($trusted)
            ? $this->written($trusted, $store, $ledger)
            : NotWritten::because(sprintf(self::OTHER_SCOPE, $post->scope()->ref(), $trusted->ref()));
    }

    /**
     * The ledger, written to the store its own environment locates for the default branch's scope; read only once
     * that store is built.
     *
     * @param Closure(): (Ledger|CannotJudge|TooLarge) $ledger
     */
    private function written(
        Scope $defaultBranch,
        ProofStore|NotWritten|CannotJudge $store,
        Closure $ledger,
    ): Written|NotWritten {
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
