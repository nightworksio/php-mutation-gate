<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\PreCheck;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Secrets;
use NightWorksIO\MutationGate\Core\Mutant\Unevidenced;
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
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doom;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
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
 * history cannot be read does too, and warns of it. A pull request's shard
 * runs in chunks, and stops once a survivor makes its run certain to fail,
 * naming that survivor in its result (ADR-0008, decision 6). Each kill's
 * evidence the runner gave is left beside its mutant, what a process printed
 * kept only where it holds no secret the gate withholds (ADR-0014,
 * decision 16).
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

        $result = match (true) {
            $followed instanceof CannotJudge => $followed,
            $id instanceof CannotJudge => $id,
            default => $this->resultOf($followed, $id, $this->deadline()),
        };

        return $result instanceof CannotJudge ? $result : $this->written($result, $results);
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
     * the verdict reads each as stopped (ADR-0008, decision 6).
     */
    public function runAllBy(Plan $plan, Path $results, Deadline|Unlimited $deadline): Written|CannotJudge
    {
        foreach ($plan as $shard) {
            $result = $this->resultOf($plan, $shard->id(), $deadline);
            $ran = $result instanceof CannotJudge ? $result : $this->written($result, $results);

            if ($ran instanceof CannotJudge) {
                return $ran;
            }

            if ($result->doomed() instanceof Doomed) {
                break;
            }
        }

        return Written::to($results->value());
    }

    /** When this process must stop: its budget from now, or never where it has none (ADR-0008, decision 1). */
    public function deadline(): Deadline|Unlimited
    {
        $budget = $this->settings->triage()->budget();

        return $budget instanceof Seconds ? Deadline::after($this->setup->clock->now(), $budget) : $budget;
    }

    /** A shard's result, left where the verdict reads it. */
    private function written(ShardResult $result, Path $results): Written|CannotJudge
    {
        return $this->adapters->project->write(
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
                ->withSteps($stopwatch->steps())
                ->measuredBy($this->setup->gate->spelt()),
        );
        $result = $this->left($result, $outcome);

        return $result->withWarnings($result->warnings()->and($history instanceof CannotJudge ? Warnings::of(
            Warning::that(sprintf(self::UNREAD_HISTORY, $id->number(), $history->why())),
        ) : Warnings::none()));
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

    /**
     * Every invocation's mutants, each limit laid on the start-up the shard
     * times once before its first mutant (see StartUpTiming), each timeout,
     * kill and mutant out of memory
     * judged by its unmutated control (see Invoking), with the survivors a second run killed, of each
     * unit but the held ones whose holding tests miss lines of them; or the
     * first cannot judge. A shard handed no map cannot judge at all. Under a
     * budget the units run in batches that fit the time left, and those the
     * time ran out before are unjudged. A shard that stops once its run
     * cannot pass runs in chunks, and the units after the chunk that doomed
     * it are unjudged too, and static analysis checks each chunk's survivors
     * before its doom is judged. Each kill with no evidence is unjudged
     * (ADR-0014, decision 17). Static analysis then checks the survivors no
     * chunk's check took up, in the time left, the analyser warmed up once
     * for the shard.
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
        $stopwatch->handled(Step::HeldCoverage, $from, $shard->units()->held());

        if ($held instanceof CannotJudge) {
            return $held;
        }

        $kept = HeldCoverage::kept($shard, $held->misses());
        $startUp = new StartUpTiming($this->adapters, StartUpSamples::standard())->of($map, $kept->units());
        $warmUp = new AnalyserWarmUp();
        $preChecking = new PreChecking(
            $this->adapters,
            $this->setup->clock,
            $deadline,
            $this->settings->staticCheck()->seconds(),
            $this->known($plan),
            $warmUp,
            $stopwatch,
            PreCheck::standard(),
        );
        $invoking = new Invoking(
            $this->adapters,
            $this->settings,
            $this->setup->clock,
            $deadline,
            $stopwatch,
            $map,
            $held->covered(),
            $preChecking,
        );
        $doom = new Dooming($this->adapters, $this->settings, $this->setup->clock)->of($plan);
        $checking = new SurvivorChecking(
            $this->adapters,
            $this->setup->clock,
            $deadline,
            $this->settings->staticCheck()->seconds(),
            $stopwatch,
            $warmUp,
        );
        $batched = new Batched($invoking, $this->setup->clock, $deadline, $this->interruption, $doom, $checking);
        $batching = Batching::opening($map->suiteDuration());
        $pruned = $plan->considered()->pruned();
        $requestFor = fn(Units $units): MutationRequest => $this->requestFor(
            $units,
            $kept->id(),
            $search,
            $startUp,
            $pruned,
        );
        $spent = match (true) {
            $doom instanceof Doom => $batched->spent(
                $this->weighed($kept, $plan),
                $batching->inChunksOf(Batching::chunk()),
                $requestFor,
            ),
            $deadline instanceof Deadline => $batched->spent($this->weighed($kept, $plan), $batching, $requestFor),
            default => $this->spentWhole($invoking, $kept, $requestFor),
        };

        if ($spent instanceof CannotJudge) {
            return $spent;
        }

        $secrets = Secrets::withheldIn($this->adapters->environment, $this->adapters->withheld);
        $evidence = Hidden::in($spent->evidence, $spent->mutants, $secrets);
        $checked = $checking->checked(
            Unevidenced::judged($spent->mutants, $evidence),
            $spent->flaky->and($spent->checked),
        );
        $mutants = $checked->mutants;

        return new Mutated(
            MutationResult::of($mutants, $spent->skipped)
                ->withWarnings($spent->warnings)
                ->withEvidence($evidence),
            $spent->flaky,
            $held,
            $spent->unjudged,
            $spent->checks->plus($checked->checks)->plus($preChecking->checks()),
            $spent->doomed,
        );
    }

    /**
     * Every invocation the shard plans, one after another.
     *
     * @param Closure(Units): MutationRequest $requestFor
     */
    private function spentWhole(Invoking $invoking, Shard $kept, Closure $requestFor): Spent|CannotJudge
    {
        $spent = Spent::none();

        foreach ($kept->invocations() as $units) {
            $invoked = $invoking->invoked($requestFor($units));

            if ($invoked instanceof CannotJudge) {
                return $invoked;
            }

            $spent = $spent->after($invoked);
        }

        return $spent;
    }

    /**
     * What the ledgers the plan reads learned of the shard's analyser: its
     * own scope's first, then the default branch's (ADR-0020, decision 11);
     * nothing where no analyser is wired.
     */
    private function known(Plan $plan): AnalyserHistory
    {
        $identity = $this->adapters->analyser;

        if (! $identity instanceof AnalyserIdentity) {
            return AnalyserHistory::of('');
        }

        $ledgers = Ledgers::read($this->adapters->proofs, Standing::planned($plan), Writing::Never);

        return $ledgers->own()->analysers()->of($identity)->and($ledgers->defaultBranch()->analysers()->of($identity));
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
            $estimated = $ledgers->estimated($this->adapters->costs, $unit, FirstRun::unmeasured(), $this->setup->gate);
            $weighed[] = Weighed::of($unit, $shard->package(), $estimated);
        }

        return $weighed;
    }

    /**
     * One invocation: a held unit alone by the tests that hold it, or files
     * by the whole suite, reading the maps the plan handed the shard, each
     * mutant's killers looked for as asked, leaving the mutators the plan
     * prunes out of the files it prunes them in.
     */
    private function requestFor(
        Units $units,
        ShardId $shard,
        KillSearch $search,
        Seconds|Unmeasured $startUp,
        Pruned $pruned,
    ): MutationRequest {
        $files = Paths::none();
        $judgedBy = WholeSuite::tests();

        foreach ($units as $unit) {
            $files = $files->with($unit->path());
            $judgedBy = $unit->judgedBy();
        }

        return RunRequest::of($this->adapters, $this->settings, $files, $judgedBy)
            ->narrowedTo($files, $this->adapters->narrowing->pruning($pruned))
            ->across(Pool::of($this->adapters->processes(), $this->settings->runner()->workers())->startingIn($startUp))
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
