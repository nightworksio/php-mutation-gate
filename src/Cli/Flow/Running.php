<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;
use function array_slice;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
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
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
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
 * out and whose limit the configured cap decided runs once more with the cap
 * doubled, where the runner can raise it, and each keeps the time its judging
 * tests take on their own, from the handed map, for timeout triage. Under
 * `tests.order: killers-first` each mutant's likely killers run first, by the
 * kill history the plan handed the shard beside its map: a shard handed none
 * orders its tests as though no test had killed anything yet, and one whose
 * history cannot be read does too, and warns of it.
 */
final readonly class Running
{
    private const string NO_HEAD = 'The commit HEAD is at cannot be read, so the plan cannot be checked against it. %s';

    private const string UNREAD_HISTORY = 'Shard %d ran its tests without the kill history the plan handed it. %s';

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** The shard `--shard` names, or the one the CI's environment names where it names none. */
    public function run(Plan $plan, ShardId|Absent $named, Path $results): Written|CannotJudge
    {
        $head = $this->adapters->repository->head();
        $followed = $head instanceof CannotTell
            ? CannotJudge::because(sprintf(self::NO_HEAD, $head->why()))
            : $plan->forCheckout($head);
        $id = $named instanceof ShardId ? $named : $this->adapters->ci->shard($plan);

        return match (true) {
            $followed instanceof CannotJudge => $followed,
            $id instanceof CannotJudge => $id,
            default => $this->ranShard($followed, $id, $results, $this->deadline()),
        };
    }

    /** Every shard of a plan, one after another in this process, each leaving its result. */
    public function runAll(Plan $plan, Path $results): Written|CannotJudge
    {
        $written = Written::to($results->value());
        $deadline = $this->deadline();

        foreach ($plan as $shard) {
            $ran = $this->ranShard($plan, $shard->id(), $results, $deadline);

            if ($ran instanceof CannotJudge) {
                return $ran;
            }
        }

        return $written;
    }

    /** When this process must stop: its budget from now, or never where it has none (ADR-0008, decision 1). */
    private function deadline(): Deadline|Unlimited
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
        $map = new Handoff($this->adapters->project)->read($id);
        $history = new Handoff($this->adapters->project)->history($id);
        $ordering = Ordering::of(
            $this->settings->triage()->order(),
            $history instanceof KillHistory ? $history : KillHistory::none(),
        );
        $outcome = $map instanceof CoverageMap ? $this->mutated($plan, $shard, $map, $ordering, $deadline) : $map;
        $ended = $this->setup->clock->now();
        $spent = Seconds::of((float) $ended->format('U.u') - (float) $started->format('U.u'));
        $identity = $this->adapters->runner->identity($this->adapters->withheld);
        $result = ShardResult::of(
            $plan->digest(),
            $id,
            $this->keysOf($shard->units(), $plan->keys()),
            $outcome instanceof CannotJudge ? $outcome : $outcome->result,
            Measurement::of($spent, $identity instanceof CannotJudge ? '' : $identity->runner(), Instant::at($ended)),
        );
        $result = $this->left($result, $outcome)->withWarnings($history instanceof CannotJudge ? Warnings::of(
            Warning::that(sprintf(self::UNREAD_HISTORY, $id->number(), $history->why())),
        ) : Warnings::none());

        return $this->adapters->project->write(
            Workspace::result($results, $id),
            Contents::of(ShardResultFile::encode($result)),
        );
    }

    /** The result with what the shard's mutating left beside its mutants: nothing where it cannot judge. */
    private function left(ShardResult $result, Mutated|CannotJudge $outcome): ShardResult
    {
        return $outcome instanceof CannotJudge ? $result : $result
            ->withFlaky($outcome->flaky)
            ->withMisses($outcome->misses)
            ->withUnjudged($outcome->unjudged);
    }

    /**
     * Every invocation's mutants, timeouts retried and timed by the map the
     * plan handed the shard, with the survivors a second run killed, of each
     * unit but the held ones whose holding tests miss lines of them; or the
     * first cannot judge. A shard handed no map cannot judge at all. Under a
     * budget the units run in batches that fit the time left, and those the
     * time ran out before are unjudged.
     */
    private function mutated(
        Plan $plan,
        Shard $shard,
        CoverageMap $map,
        Ordering $ordering,
        Deadline|Unlimited $deadline,
    ): Mutated|CannotJudge {
        $misses = new HeldCoverage($this->adapters)->misses($shard, $map);

        if ($misses instanceof CannotJudge) {
            return $misses;
        }

        $kept = HeldCoverage::kept($shard, $misses);
        $invoking = new Invoking($this->adapters, $this->settings, $this->setup->clock, $deadline);
        $spent = $deadline instanceof Deadline
            ? $this->spentWithin($invoking, $deadline, $plan, $kept, $map, $ordering)
            : $this->spentWhole($invoking, $kept, $ordering);

        return $spent instanceof CannotJudge ? $spent : new Mutated(
            MutationResult::of(TimeoutTriage::timed($spent->mutants, $map), $spent->skipped),
            $spent->flaky,
            $misses,
            $spent->unjudged,
        );
    }

    /** Every invocation the shard plans, one after another. */
    private function spentWhole(Invoking $invoking, Shard $kept, Ordering $ordering): Spent|CannotJudge
    {
        $spent = Spent::none($this->settings->triage()->retries());

        foreach ($kept->invocations() as $units) {
            $invoked = $invoking->invoked($this->requestFor($units, $kept->id(), $ordering), $spent->retries);

            if ($invoked instanceof CannotJudge) {
                return $invoked;
            }

            $spent = $spent->after($invoked);
        }

        return $spent;
    }

    /**
     * The shard's units in the order the plan lists them, riskiest first, in
     * batches that each fit the time left, until one does not or the time is
     * up. A batch the runner cannot judge once the time is up is unjudged, as
     * is every unit no batch took.
     */
    private function spentWithin(
        Invoking $invoking,
        Deadline $deadline,
        Plan $plan,
        Shard $kept,
        CoverageMap $map,
        Ordering $ordering,
    ): Spent|CannotJudge {
        $queue = $this->weighed($kept, $plan);
        $batching = Batching::opening($map->suiteDuration());
        $spent = Spent::none($this->settings->triage()->retries());
        $left = $deadline->left($this->setup->clock->now());
        $batch = $batching->next($queue, $left);

        while ($batch->count() > 0) {
            $request = $this->requestFor($batch, $kept->id(), $ordering)->within($left);
            $invoked = $invoking->invoked($request, $spent->retries);
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
            $batch = $batching->next($queue, $left);
        }

        return $spent->leaving(Units::of(...array_map(static fn(Weighed $weighed): Unit => $weighed->unit(), $queue)));
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
     * by the whole suite, reading the map the plan handed the shard, its
     * tests in the order asked.
     */
    private function requestFor(Units $units, ShardId $shard, Ordering $ordering): MutationRequest
    {
        $files = Paths::none();
        $judgedBy = WholeSuite::tests();

        foreach ($units as $unit) {
            $files = $files->with($unit->path());
            $judgedBy = $unit->judgedBy();
        }

        return MutationRequest::of($files, $judgedBy)
            ->across($this->adapters->runner->behaviour()->parallelism()->processes($this->adapters->cores))
            ->reusingCoverage(Workspace::shardCoverage($shard))
            ->withholding($this->adapters->withheld)
            ->orderedBy($ordering);
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
