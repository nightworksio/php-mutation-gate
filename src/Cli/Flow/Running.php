<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;
use function array_slice;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Plan\Batching;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\MemoryTriage;
use NightWorksIO\MutationGate\Core\Verdict\TimeoutTriage;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

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
 * history cannot be read does too, and warns of it.
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
    ) {
    }

    /** The same, stopping before its next batch once this says so, as at its deadline. */
    public function interrupted(Interruption $interruption): self
    {
        return clone($this, ['interruption' => $interruption]);
    }

    /** The shard `--shard` names, or, where it names none, the one the environment's variables name (WhichShard). */
    public function run(Plan $plan, ShardId|Absent $named, Path $results): Written|CannotJudge
    {
        $head = $this->adapters->repository->head();
        $followed = $head instanceof CannotTell
            ? CannotJudge::because(sprintf(self::NO_HEAD, $head->why()))
            : $plan->forCheckout($head);
        $id = $named instanceof ShardId ? $named : WhichShard::in($this->adapters->environment, $plan);

        return match (true) {
            $followed instanceof CannotJudge => $followed,
            $id instanceof CannotJudge => $id,
            default => $this->ranShard($followed, $id, $results, $this->deadline()),
        };
    }

    /** Every shard of a plan, one after another in this process, each leaving its result. */
    public function runAll(Plan $plan, Path $results): Written|CannotJudge
    {
        return $this->runAllBy($plan, $results, $this->deadline());
    }

    /**
     * Every shard of a plan, as {@see runAll()} runs them, stopping at a
     * deadline set before it, which several runs of one process share.
     */
    public function runAllBy(Plan $plan, Path $results, Deadline|Unlimited $deadline): Written|CannotJudge
    {
        $written = Written::to($results->value());

        foreach ($plan as $shard) {
            $ran = $this->ranShard($plan, $shard->id(), $results, $deadline);

            if ($ran instanceof CannotJudge) {
                return $ran;
            }
        }

        return $written;
    }

    /** When this process must stop: its budget from now, or never where it has none (ADR-0008, decision 1). */
    public function deadline(): Deadline|Unlimited
    {
        $budget = $this->settings->triage()->budget();

        return $budget instanceof Seconds ? Deadline::after($this->setup->clock->now(), $budget) : $budget;
    }

    private function ranShard(Plan $plan, ShardId $id, Path $results, Deadline|Unlimited $deadline): Written|CannotJudge
    {
        $shard = $plan->shard($id);

        if ($shard instanceof CannotJudge) {
            return $shard;
        }

        $started = $this->setup->clock->now();
        $stopwatch = new Stopwatch($this->setup->clock, $started);
        $map = new Handoff($this->adapters->project, Handoff::limits())->read($id);
        $history = new Handoff($this->adapters->project, Handoff::limits())->history($id);
        $ordering = Ordering::of(
            $this->settings->triage()->order(),
            $history instanceof KillHistory ? $history : KillHistory::none(),
        );
        $search = KillSearch::of($ordering, $plan->briefing()->matrix());
        $outcome = $map instanceof CoverageMap
            ? $this->mutated($plan, $shard, $map, $search, $deadline, $stopwatch)
            : $map;
        $ended = $this->setup->clock->now();
        $spent = Seconds::between($started, $ended);
        $identity = $this->adapters->runner->identity($this->adapters->withheld);
        $result = ShardResult::of(
            $plan->digest(),
            $id,
            $this->keysOf($shard->units(), $plan->keys()),
            $outcome instanceof CannotJudge ? $outcome : $outcome->result,
            Measurement::of($spent, $identity instanceof CannotJudge ? '' : $identity->runner(), Instant::at($ended))
                ->withSteps($stopwatch->steps()),
        );
        $result = $this->left($result, $outcome);
        $result = $result->withWarnings($result->warnings()->and($history instanceof CannotJudge ? Warnings::of(
            Warning::that(sprintf(self::UNREAD_HISTORY, $id->number(), $history->why())),
        ) : Warnings::none()));

        return $this->adapters->project->write(
            Workspace::result($results, $id),
            Contents::of(ShardResultFile::encode($result)),
        );
    }

    /** The result with what the shard's mutating left beside its mutants, warnings too: none where it cannot judge. */
    private function left(ShardResult $result, Mutated|CannotJudge $outcome): ShardResult
    {
        return $outcome instanceof CannotJudge ? $result : $result
            ->withFlaky($outcome->flaky)
            ->withMisses($outcome->held->misses())
            ->withCovered($outcome->held->covered())
            ->withUnjudged($outcome->unjudged)
            ->withChecks($outcome->checks)
            ->withWarnings($outcome->result->warnings());
    }

    /**
     * Every invocation's mutants, timeouts timed by the map the
     * plan handed the shard, those out of memory weighed by the suite's peak
     * the plan measured, with the survivors a second run killed, of each
     * unit but the held ones whose holding tests miss lines of them; or the
     * first cannot judge. A shard handed no map cannot judge at all. Under a
     * budget the units run in batches that fit the time left, and those the
     * time ran out before are unjudged. Static analysis then checks the
     * survivors, in the time left.
     */
    private function mutated(
        Plan $plan,
        Shard $shard,
        CoverageMap $map,
        KillSearch $search,
        Deadline|Unlimited $deadline,
        Stopwatch $stopwatch,
    ): Mutated|CannotJudge {
        $from = $stopwatch->now();
        $held = new HeldCoverage($this->adapters)->checked($shard, $map);
        $holding = $shard->units()->held();

        if ($holding > 0) {
            $stopwatch->stop(Step::HeldCoverage, $from, $holding);
        }

        if ($held instanceof CannotJudge) {
            return $held;
        }

        $kept = HeldCoverage::kept($shard, $held->misses());
        $invoking = new Invoking($this->adapters, $this->settings, $this->setup->clock, $deadline, $stopwatch);
        $spent = $deadline instanceof Deadline
            ? $this->spentWithin($invoking, $deadline, $plan, $kept, $map, $search)
            : $this->spentWhole($invoking, $kept, $search);

        if ($spent instanceof CannotJudge) {
            return $spent;
        }

        $from = $stopwatch->now();
        $checked = new SurvivorChecking(
            $this->adapters,
            $this->setup->clock,
            $deadline,
            $this->settings->staticCheck()->seconds(),
        )
            ->checked($spent->mutants, $spent->flaky);
        $survived = $spent->mutants->counting(MutantStatus::Survived);

        if ($survived > 0) {
            $stopwatch->stop(Step::StaticCheck, $from, $survived);
        }

        return new Mutated(
            MutationResult::of(
                MemoryTriage::weighed(TimeoutTriage::timed($checked->mutants, $map), $plan->briefing()->peak()),
                $spent->skipped,
            )->withWarnings($spent->warnings),
            $spent->flaky,
            $held,
            $spent->unjudged,
            $checked->checks,
        );
    }

    /** Every invocation the shard plans, one after another. */
    private function spentWhole(Invoking $invoking, Shard $kept, KillSearch $search): Spent|CannotJudge
    {
        $spent = Spent::none();

        foreach ($kept->invocations() as $units) {
            $invoked = $invoking->invoked($this->requestFor($units, $kept->id(), $search));

            if ($invoked instanceof CannotJudge) {
                return $invoked;
            }

            $spent = $spent->after($invoked);
        }

        return $spent;
    }

    /**
     * The shard's units in the order the plan lists them, riskiest first, in
     * batches that each fit the time left, until one does not, the time is
     * up, or the run is interrupted. A batch the runner cannot judge once the
     * time is up is unjudged, as is every unit no batch took.
     */
    private function spentWithin(
        Invoking $invoking,
        Deadline $deadline,
        Plan $plan,
        Shard $kept,
        CoverageMap $map,
        KillSearch $search,
    ): Spent|CannotJudge {
        $queue = $this->weighed($kept, $plan);
        $batching = Batching::opening($map->suiteDuration());
        $spent = Spent::none();
        $left = $deadline->left($this->setup->clock->now());
        $batch = $this->batchOf($batching, $queue, $left);

        while ($batch->count() > 0) {
            $request = $this->requestFor($batch, $kept->id(), $search)->within($left);
            $invoked = $invoking->invoked($request);
            $queue = array_slice($queue, $batch->count());
            $spent = match (true) {
                ! $invoked instanceof CannotJudge => $spent->after($invoked),
                $deadline->hasPassed($this->setup->clock->now()) => $spent->leaving($batch),
                default => $invoked,
            };

            if ($spent instanceof CannotJudge) {
                return $spent;
            }

            $left = $deadline->left($this->setup->clock->now());
            $batch = $this->batchOf($batching, $queue, $left);
        }

        return $spent->leaving(Units::of(...array_map(static fn(Weighed $weighed): Unit => $weighed->unit(), $queue)));
    }

    /**
     * The next batch that fits the time left, or none once the run is interrupted.
     *
     * @param list<Weighed> $queue
     */
    private function batchOf(Batching $batching, array $queue, Seconds $left): Units
    {
        return $this->interruption->arrived() ? Units::none() : $batching->next($queue, $left);
    }

    /**
     * The shard's units in order, each weighed by what the cost model expects
     * of it, with what the ledgers the plan reads learned; a shard measures
     * no first run of its own.
     *
     * @return list<Weighed>
     */
    private function weighed(Shard $shard, Plan $plan): array
    {
        $ledgers = Ledgers::read($this->adapters->proofs, Standing::planned($plan), Writing::Never);
        $weighed = [];

        foreach ($shard->units() as $unit) {
            $estimated = $ledgers->estimated($this->adapters->costs, $unit, FirstRun::unmeasured());
            $weighed[] = Weighed::of($unit, $shard->package(), $estimated);
        }

        return $weighed;
    }

    /**
     * One invocation: a held unit alone by the tests that hold it, or files
     * by the whole suite, reading the maps the plan handed the shard, each
     * mutant's killers looked for as asked.
     */
    private function requestFor(Units $units, ShardId $shard, KillSearch $search): MutationRequest
    {
        $files = Paths::none();
        $judgedBy = WholeSuite::tests();

        foreach ($units as $unit) {
            $files = $files->with($unit->path());
            $judgedBy = $unit->judgedBy();
        }

        return RunRequest::of($this->adapters, $this->settings, $files, $judgedBy)
            ->across(Pool::of($this->adapters->processes(), $this->settings->runner()->workers()))
            ->reusingCoverage(Handed::maps(Workspace::shardCoverage($shard), Workspace::coverage()))
            ->searching($search);
    }

    private function keysOf(Units $units, Keys $planned): Keys
    {
        $keys = Keys::none();

        foreach ($units as $unit) {
            $keys = $keys->with($unit->path(), $planned->keyOf($unit->path()));
        }

        return $keys;
    }
}
