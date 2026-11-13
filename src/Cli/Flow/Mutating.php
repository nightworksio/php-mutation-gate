<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreCheck;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Secrets;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Mutant\Unevidenced;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Plan\Batching;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Runner\LineRecording;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doom;

/**
 * A shard's units mutated and judged: each held unit alone against the tests
 * that hold it, then the shard's other units against the whole suite or the
 * shard's part of it, in batches or chunks as its budget and `run.failFast`
 * ask, each kill's evidence kept only where it holds no secret the gate
 * withholds (ADR-0008, ADR-0014).
 *
 * @internal
 */
final readonly class Mutating
{
    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private Setup $setup,
        private Interruption $interruption,
        private Ruling $ruling,
        private BatchEnds $ends,
        private ProcessLog $log,
        private ShardRecording $recording,
    ) {
    }

    /**
     * Every invocation's mutants, each limit laid on the start-up the shard
     * times once before its first mutant (see StartUpTiming), each timeout,
     * kill and mutant out of memory
     * judged by its unmutated control (see Invoking), with the survivors a second run killed, of each
     * unit but the held ones whose holding tests miss lines of them; or the
     * first cannot judge. A shard handed no map cannot judge at all. Under a
     * budget the units run in batches that fit the time left, and those the
     * time ran out before are unjudged; a shard's job limit starts every unit
     * and stops the runner (ADR-0114). A shard that stops once its run
     * cannot pass runs in chunks, and the units after the chunk that doomed
     * it are unjudged too, and static analysis checks each chunk's survivors
     * before its doom is judged. Each kill with no evidence is unjudged
     * (ADR-0014). Static analysis then checks the survivors no
     * chunk's check took up, in the time left, the analyser warmed up once
     * for the shard. Where `paths` entries match some of the shard's units,
     * each group of them runs on its own, as PartitionedRuns says (ADR-0074).
     */
    public function mutated(
        Plan $plan,
        Shard $shard,
        CoverageMap $map,
        KillSearch $search,
        Deadline|Unlimited $deadline,
        Stopwatch $stopwatch,
    ): Mutated|CannotJudge {
        $from = $stopwatch->now();
        $held = new HeldCoverage($this->adapters, $this->settings->reach()->heldCoverage())
            ->checked($shard, $map, $plan->briefing()->isEarly());
        $stopwatch->handled(Step::HeldCoverage, $from, $shard->units()->held());

        if ($held instanceof CannotJudge) {
            return $held;
        }

        $kept = HeldCoverage::kept($shard, $held->misses());
        $groups = PathGroups::of($kept, $this->settings->effective()->setup()->paths());

        return $groups->isWhole()
            ? $this->mutatedKept($plan, $kept, $map, $held, $search, $deadline, $stopwatch)
            : new PartitionedRuns($this->adapters, $this->settings, $this->ruling, $this->setup->clock->now())->mutated(
                $groups,
                fn(Adapters $adapters, Settings $settings, Shard $part): Mutated|CannotJudge => new self(
                    $adapters,
                    $settings,
                    $this->setup,
                    $this->interruption,
                    $this->ruling,
                    $this->ends,
                    $this->log,
                    $this->recording,
                )->mutatedKept($plan, $part, $map, $held, $search, $deadline, $stopwatch),
            );
    }

    /** The runs of a shard's units whose held coverage was checked, each with this run's adapters and settings. */
    private function mutatedKept(
        Plan $plan,
        Shard $kept,
        CoverageMap $map,
        HeldChecks $held,
        KillSearch $search,
        Deadline|Unlimited $deadline,
        Stopwatch $stopwatch,
    ): Mutated|CannotJudge {
        $samples = $this->settings->shards()->planning()->startUpSamples();
        $startUp = new StartUpTiming($this->adapters, $samples)->of($map, $kept->units());
        $pool = $this->adapters->pooled($this->settings->runner()->workers())
            ->pacedBy($this->settings->runner()->pace($this->settings->triage()->bounds()->unmeasuredStartUp()))
            ->startingIn($startUp)
            ->learning($plan->briefing()->learned()->multiple());
        $warmUp = new AnalyserWarmUp($this->settings->staticCheck()->dependents());
        $analyser = $this->adapters->analyser;
        $preChecking = $this->settings->staticCheck()->before() && $analyser instanceof AnalyserIdentity
            ? new PreChecking(
                $this->adapters,
                $this->setup->clock,
                $deadline,
                $this->settings->staticCheck()->seconds(),
                $this->known($plan, $analyser),
                $warmUp,
                $stopwatch,
                PreCheck::briefed($plan->briefing())->after($this->settings->staticCheck()->preCheckAfter()),
            )
            : new NoPreCheck();
        $checking = new SurvivorChecking(
            $this->adapters,
            $this->setup->clock,
            $deadline,
            $this->settings->staticCheck()->seconds(),
            $stopwatch,
            $warmUp,
            $this->settings->toggles()->on(Toggle::StaticCheckAfter),
        );
        $invoking = new Invoking(
            $this->adapters,
            $this->settings,
            $this->setup->clock,
            $deadline,
            $stopwatch,
            $map,
            $held->covered(),
            $checking,
            new PartedRuns($this->adapters, $this->settings, $map, $held->covered(), $this->log),
            $preChecking,
        );
        $doom = new Dooming($this->adapters, $this->settings, $this->setup)->of($plan);
        $stops = $this->settings->reach()->failingFast()->mode()->stops();
        $batched = new Batched(
            $invoking,
            $this->setup->clock,
            $deadline,
            ShardDeadline::fittedTo($this->settings, $deadline),
            $this->interruption,
            $doom,
            $stops,
            $this->ends->digested($plan->digests()),
        );
        $batching = Batching::opening($map->suiteDuration());
        $pruned = $plan->considered()->pruned();
        $requestFor = fn(Units $units): MutationRequest => $this->requestFor(
            $units,
            $kept,
            $search,
            $pool,
            $pruned,
            $plan->briefing()->isEarly(),
        );
        $spent = match (true) {
            $doom instanceof Doom && $stops => $batched->spent(
                $this->weighed($kept, $plan),
                $batching->inChunksOf($this->settings->shards()->planning()->chunk()),
                $requestFor,
            ),
            $this->settings->triage()->budget() instanceof Seconds
                => $batched->spent($this->weighed($kept, $plan), $batching, $requestFor),
            default => new Unbatched($invoking, $this->setup->clock, $doom, $deadline)->spent($kept, $requestFor),
        };

        if ($spent instanceof CannotJudge) {
            return $spent;
        }

        $secrets = Secrets::withheldIn($this->adapters->environment, $this->adapters->withheld);
        $evidence = Hidden::in($spent->evidence, $spent->mutants, $secrets);
        $mutants = Unevidenced::judged($spent->mutants, $evidence);

        return new Mutated(
            MutationResult::of($mutants, $spent->skipped)
                ->withWarnings($spent->warnings)
                ->withEvidence($evidence),
            $spent->flaky,
            $held,
            $spent->unjudged,
            $spent->checks->plus(
                $preChecking instanceof PreChecking ? $preChecking->checks() : SurvivorChecks::none(),
            ),
            $spent->doomed,
        );
    }

    /**
     * What the ledgers the plan reads learned of the shard's analyser: its
     * own scope's first, then the default branch's (ADR-0020).
     */
    private function known(Plan $plan, AnalyserIdentity $identity): AnalyserHistory
    {
        $ledgers = Ledgers::read(
            $this->adapters->proofs,
            Standing::planned($plan),
            Writing::Never,
            $this->settings->proofs()->taken(),
        );

        return $ledgers->own()->learned()->analysers()->of($identity)
            ->and($ledgers->defaultBranch()->learned()->analysers()->of($identity));
    }

    /**
     * The shard's units in order, each weighed by what the cost model expects
     * of it, with what the ledgers the plan reads learned, at its share of the
     * shard's part of the test files; a shard measures no first run of its own.
     *
     * @return list<Weighed>
     */
    private function weighed(Shard $shard, Plan $plan): array
    {
        $ledgers = Ledgers::read(
            $this->adapters->proofs,
            Standing::planned($plan),
            Writing::Never,
            $this->settings->proofs()->taken(),
        );
        $costs = $ledgers->costs($this->setup->gate);
        $weighed = [];

        foreach ($shard->units() as $unit) {
            $estimated = $costs->estimated($this->adapters->costs, $unit, FirstRun::unmeasured());
            $weighed[] = Weighed::of($unit, $shard->package(), $estimated)->sharedAmong($shard->testPart());
        }

        return $weighed;
    }

    /**
     * One invocation: a held unit alone by the tests that hold it, or files
     * by the whole suite, reading the maps the plan handed the shard, each
     * mutant's killers looked for as asked, leaving the mutators the plan
     * prunes out of the files it prunes them in, its mutants judged by the
     * shard's part of the test files (ADR-0117); in a run planned without a
     * fresh map, its streams recording each test's lines (ADR-0135).
     */
    private function requestFor(
        Units $units,
        Shard $shard,
        KillSearch $search,
        Pool $pool,
        Pruned $pruned,
        bool $early,
    ): MutationRequest {
        $files = Paths::none();
        $judgedBy = WholeSuite::tests();

        foreach ($units as $unit) {
            $files = $files->with($unit->path());
            $judgedBy = $unit->judgedBy();
        }

        $request = RunRequest::of($this->adapters, $this->settings, $files, $judgedBy);
        $narrowing = $request->narrowing()->pruning($pruned)->inTestPart($shard->testPart());
        $narrowing = match (true) {
            $early => $narrowing->recordingInto(LineRecording::standingIn($this->recording->directory($shard))),
            $this->recording->recordsLines() => $narrowing->recordingInto(
                LineRecording::forTheMap($this->recording->directory($shard)),
            ),
            default => $narrowing,
        };

        return $request->narrowedTo($files, $narrowing)
            ->across($pool)
            ->reusingCoverage(Handed::maps(Workspace::shardCoverage($shard->id()), Workspace::coverage()))
            ->searching($search);
    }
}
