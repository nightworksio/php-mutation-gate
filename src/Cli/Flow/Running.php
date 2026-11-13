<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactAccess;
use NightWorksIO\MutationGate\Adapter\GitHub\WatchedJob;
use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Adapter\Process\ErrorOutputLog;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Proof\GateRelease;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\NoProcessLog;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Repository;

use function sprintf;

use Symfony\Component\HttpClient\HttpClient;

/**
 * `run --plan`: one shard of a plan, on the commit the plan was made on. Each
 * held unit runs alone against the tests that hold it, then the shard's other
 * units against the whole suite, reading the coverage map the plan handed it. The
 * shard leaves every mutant's record, each unit's key and what it measured,
 * or the runner's cannot judge, for the verdict. Each mutant whose time ran
 * out is never run again, and keeps the time its judging tests take on their
 * own, from the handed map, for timeout triage. Under
 * `tests.order: killers-first` each mutant's likely killers run first, by the
 * kill history the plan handed the shard beside its map: a shard handed none
 * orders its tests as though no test had killed anything yet, and one whose
 * history cannot be read does too, and warns of it. A pull request's shard
 * runs in chunks, and stops once a survivor makes its run certain to fail,
 * naming that survivor in its result (ADR-0008). Each kill's
 * evidence the runner gave is left beside its mutant, what a process printed
 * kept only where it holds no secret the gate withholds (ADR-0014).
 *
 * @internal
 */
final readonly class Running
{
    private const string NO_HEAD = 'The commit HEAD is at cannot be read, so the plan cannot be checked against it. %s';

    private const string UNREAD_HISTORY = 'Shard %d ran its tests without the kill history the plan handed it. %s';

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private Setup $setup,
        private Interruption $interruption = new Interruption(),
        private Ruling $ruling = new Ruling(),
        private BatchEnds $ends = new BatchEnds(),
        private ProcessLog $log = new NoProcessLog(),
    ) {
    }

    /** The shards a composed flow runs, each group of units `paths` entries match with a runner of its own. */
    public static function composed(Composed $composed): self
    {
        return new self(
            $composed->adapters,
            $composed->settings,
            $composed->setup,
            ruling: $composed->ruling,
            log: ErrorOutputLog::onErrorOutput(),
        );
    }

    /** The same, stopping before its next batch once this says so, as at its deadline. */
    public function interrupted(Interruption $interruption): self
    {
        return new self(
            $this->adapters,
            $this->settings,
            $this->setup,
            $interruption,
            $this->ruling,
            $this->ends,
            $this->log,
        );
    }

    /** The same, telling these what each batch judged as it ends (ADR-0120). */
    public function telling(BatchEnds $ends): self
    {
        return new self(
            $this->adapters,
            $this->settings,
            $this->setup,
            $this->interruption,
            $this->ruling,
            $ends,
            $this->log,
        );
    }

    /** The plan, where the checkout is the commit it was made on, as every shard and the verdict need. */
    public static function followed(Plan $plan, Repository $repository): Plan|CannotJudge
    {
        $head = $repository->head();

        return $head instanceof CannotTell
            ? CannotJudge::because(sprintf(self::NO_HEAD, $head->why()))
            : $plan->forCheckout($head);
    }

    /**
     * The shard `--shard` names, or, where it names none, the one the
     * environment's variables name (WhichShard); where the plan's shards pull
     * their work, the items that job takes, claimed through the artifact
     * service the gate was handed (ADR-0134).
     */
    public function run(
        Plan $plan,
        ShardId|Absent $named,
        Path $results,
        ArtifactAccess|CannotJudge|NotGiven $service = new NotGiven(),
    ): ShardLeft|ShardsLeft|CannotJudge {
        $followed = self::followed($plan, $this->adapters->repository);
        $id = $named instanceof ShardId ? $named : WhichShard::in($this->adapters->environment, $plan);

        if ($followed instanceof Plan && $id instanceof ShardId && $followed->pulls()) {
            $deadline = ShardDeadline::of($this->settings, $this->setup->clock->now());

            return new PulledRuns($this->adapters, $this->settings)->left(
                $followed,
                $id,
                $results,
                $service,
                fn(ShardId $item): ShardResult|CannotJudge => $this->resultLeft($followed, $item, $results, $deadline),
            );
        }

        $stopped = WatchedJob::of(
            $this->adapters->environment,
            $service,
            HttpExchange::over(HttpClient::create()),
        )->stopped();

        $result = match (true) {
            $followed instanceof CannotJudge => $followed,
            $id instanceof CannotJudge => $id,
            $stopped !== '' => CannotJudge::because($stopped),
            default => $this->resultOf($followed, $id, ShardDeadline::of($this->settings, $this->setup->clock->now())),
        };

        if ($followed instanceof Plan && $id instanceof ShardId) {
            $this->recording()->left($followed, $id, $results);
        }

        return ShardLeft::after($result, $this->written($result, $results));
    }

    /** Every shard of a plan, one after another in this process, each leaving its result. */
    public function runAll(Plan $plan, Path $results): Written|CannotJudge
    {
        return $this->runAllBy($plan, $results, $this->deadline());
    }

    /**
     * Every shard of a plan, as {@see runAll()} runs them, stopping at a
     * deadline set before it, which several runs of one process share. Once
     * a shard stops because the run cannot pass, no later shard runs, and
     * the verdict reads each as stopped (ADR-0008, ADR-0103).
     */
    public function runAllBy(Plan $plan, Path $results, Deadline|Unlimited $deadline): Written|CannotJudge
    {
        foreach ($plan as $shard) {
            $result = $this->resultLeft($plan, $shard->id(), $results, $deadline);
            $ran = $this->written($result, $results);

            if ($ran instanceof CannotJudge) {
                return $ran;
            }

            if ($result instanceof ShardResult && $result->doomed()->stopped()) {
                break;
            }
        }

        return Written::to($results->value());
    }

    /** When this process must stop: its budget from now, or never where it has none (ADR-0008). */
    public function deadline(): Deadline|Unlimited
    {
        $budget = $this->settings->triage()->budget();

        return $budget instanceof Seconds ? Deadline::after($this->setup->clock->now(), $budget) : $budget;
    }

    /** What one shard of a plan came to, with what its streams recorded left where the run reads it. */
    private function resultLeft(
        Plan $plan,
        ShardId $id,
        Path $results,
        Deadline|Unlimited $deadline,
    ): ShardResult|CannotJudge {
        $result = $this->resultOf($plan, $id, $deadline);
        $this->recording()->left($plan, $id, $results);

        return $result;
    }

    /** What the shard's streams record of each test's lines. */
    private function recording(): ShardRecording
    {
        return new ShardRecording($this->adapters, $this->settings);
    }

    /** A shard's result, left where the verdict reads it; or why it has none. */
    private function written(ShardResult|CannotJudge $result, Path $results): Written|CannotJudge
    {
        return $result instanceof CannotJudge ? $result : $this->adapters->project->write(
            Workspace::result($results, $result->shard()),
            Contents::of(ShardResultFile::encode($result)),
        );
    }

    /** What one shard of a plan came to, or why it has no result. */
    private function resultOf(Plan $plan, ShardId $id, Deadline|Unlimited $deadline): ShardResult|CannotJudge
    {
        $shard = $plan->shard($id);

        if ($shard instanceof CannotJudge) {
            return $shard;
        }

        $started = $this->setup->clock->now();
        $stopwatch = new Stopwatch($this->setup->clock, $started);
        $handoff = new Handoff(
            $this->adapters->project,
            Handoff::limits(),
            $this->adapters->maps,
            $this->settings->proofs()->coverageCompressionLevel(),
        );
        $map = $handoff->read($id);
        $history = $handoff->history($id);
        $ordering = Ordering::of(
            $this->settings->triage()->order(),
            $history instanceof KillHistory ? $history : KillHistory::none(),
        );
        $held = $handoff->subsumption($id);
        $search = KillSearch::of($ordering, $plan->briefing()->profile()->matrix())
            ->controllingKills($this->settings->triage()->flaky()->killControls());
        $search = $held instanceof Subsumption ? $search->holdingBack($held) : $search;
        $outcome = $map instanceof CoverageMap
            ? new Mutating(
                $this->adapters,
                $this->settings,
                $this->setup,
                $this->interruption,
                $this->ruling,
                $this->ends,
                $this->log,
                $this->recording(),
            )->mutated($plan, $shard, $map, $search, $deadline, $stopwatch)
            : $map;
        $ended = $this->setup->clock->now();
        $spent = Seconds::between($started, $ended);
        $identity = $this->adapters->runner->identity($this->adapters->withheld);
        $result = ShardResult::of(
            $plan->digest(),
            $id,
            $plan->keys()->of($shard->units()),
            $outcome instanceof CannotJudge ? $outcome : $outcome->result,
            Measurement::of($spent, $identity instanceof CannotJudge ? '' : $identity->runner(), Instant::at($ended))
                ->withSteps($stopwatch->steps())
                ->measuredBy(GateRelease::of($this->setup->gate)),
        );
        $result = $this->left($result, $outcome);

        return $result->withWarnings($result->warnings()->and($history instanceof CannotJudge ? Warnings::of(
            Warning::noting(sprintf(self::UNREAD_HISTORY, $id->number(), $history->why())),
        ) : Warnings::none())->and(Unsplit::warnings($this->settings, $id)));
    }

    /** The result with what the shard's mutating left beside its mutants, warnings too: none where it cannot judge. */
    private function left(ShardResult $result, Mutated|CannotJudge $outcome): ShardResult
    {
        return $outcome instanceof CannotJudge ? $result : $result
            ->withFlaky($outcome->flaky)
            ->withHeld($outcome->held)
            ->withUnjudged($outcome->unjudged)
            ->withChecks($outcome->checks)
            ->withWarnings($outcome->result->warnings())
            ->withDoomed($outcome->doomed);
    }
}
