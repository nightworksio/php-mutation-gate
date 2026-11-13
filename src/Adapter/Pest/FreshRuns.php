<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_keys;
use function array_map;

use Closure;

use function count;
use function getmypid;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function strval;

/**
 * Each mutant a Pest run left unjudged because its own process had loaded
 * the mutant's file before the mutant was in place, judged again under
 * `pest.preloaded: fresh` (ADR-0148). Its covering tests, selected as its
 * own run selected them, by their filter where it fits and every test of
 * the tests that judge the request otherwise, run in a fresh `pest` process
 * whose PHP has opcache off and prepends the gate's override (see
 * MutantPlacement), so the mutant, as Pest prints it, is served before
 * Composer's autoloader loads anything. Each run is allowed the standard
 * limit of its covering tests' own time under the request's memory cap, and
 * none of the gate's plugin's variables reaches it. The runs go side by side
 * in the places of the request's pool, none started once the time left has
 * run out, and a mutant whose run never started stays as it was. Each is
 * judged by Pest's JUnit log, its exit status and the wrapper's guard (see
 * FreshVerdict); a kill no test is named for stands only once the same run,
 * through the same override serving the file as Pest prints it unmutated,
 * passes, those runs side by side in turn.
 *
 * @internal
 */
final readonly class FreshRuns
{
    /** The file the runs' memory cap is written beside, among the adapter's own. */
    private const string BESIDE = 'pest/fresh/runs';

    private FreshRunPreparing $preparing;

    public function __construct(
        private Project $project,
        private MutantPlacement $placement,
        private Shell $shell,
        private CapFiles $files,
        Patching $patching,
        private LimitBounds $bounds,
    ) {
        $this->preparing = new FreshRunPreparing($project, $placement, $patching);
    }

    /**
     * The result, each mutant it left unjudged as preloaded judged again in
     * a fresh run, its covering tests read from this coverage, and its
     * evidence and step times added: the runs under `mutation`, the
     * unmutated runs under `controls`. A mutant whose run cannot be written
     * is unjudged, saying why.
     *
     * @param Closure(): (Seconds|Unlimited) $left the time left to start a run in
     */
    public function judged(
        MutationResult $result,
        MutationRequest $request,
        Covering $coverage,
        Closure $left,
        Laps $laps,
    ): MutationResult {
        $preloaded = self::preloadedIn($result->mutants());

        if ($preloaded === []) {
            return $result;
        }

        $override = $this->placement->override(OwnArchive::running());
        $beside = $override instanceof CannotJudge ? $override : $this->project->fresh(self::BESIDE);
        $scan = $beside instanceof CannotJudge
            ? $beside
            : MemoryScan::beside($this->project, $beside, $request->memory(), $this->files);

        return match (true) {
            $override instanceof CannotJudge => $this->unjudged($result, $preloaded, $override),
            $scan instanceof CannotJudge => $this->unjudged($result, $preloaded, $scan),
            default => $this->ran(
                $result,
                $preloaded,
                $left,
                $laps,
                $this->batch($request, $coverage, $override, $scan),
            ),
        };
    }

    /** Whether a run left any of these mutants unjudged because its own process had loaded its file first. */
    public static function findAny(Mutants $mutants): bool
    {
        return self::preloadedIn($mutants) !== [];
    }

    /** What every fresh run of this request shares. */
    private function batch(MutationRequest $request, Covering $coverage, string $override, MemoryScan $scan): FreshBatch
    {
        return new FreshBatch(
            $request,
            $coverage,
            $coverage->map($this->project),
            Suite::of($coverage->tests()),
            $request->pool()->bounding($this->bounds),
            $override,
            $scan,
        );
    }

    /**
     * The mutants a run left unjudged because their own process had loaded
     * their file before the mutant was in place.
     *
     * @return list<Mutant>
     */
    private static function preloadedIn(Mutants $mutants): array
    {
        $preloaded = [];

        foreach ($mutants as $mutant) {
            $reason = $mutant->reason();
            $preloaded = [
                ...$preloaded,
                ...$mutant->status() === MutantStatus::Unjudged && $reason instanceof Reason && $reason->isPreloaded()
                    ? [$mutant]
                    : [],
            ];
        }

        return $preloaded;
    }

    /**
     * The result, each of these mutants unjudged for why none of their runs can be written.
     *
     * @param list<Mutant> $preloaded
     */
    private function unjudged(MutationResult $result, array $preloaded, CannotJudge $why): MutationResult
    {
        $reason = Reason::that($why->why());
        $unjudged = array_map(
            static fn(Mutant $mutant): Mutant => Interpretation::unjudged($mutant, $reason),
            $preloaded,
        );

        return $result->withMutants(FoundAgain::replacing($result->mutants(), Mutants::of(...$unjudged)));
    }

    /**
     * The result, each of these mutants judged by its fresh run, side by
     * side with the others, and by its control where its verdict asks for
     * one.
     *
     * @param list<Mutant>                   $preloaded
     * @param Closure(): (Seconds|Unlimited) $left
     */
    private function ran(
        MutationResult $result,
        array $preloaded,
        Closure $left,
        Laps $laps,
        FreshBatch $batch,
    ): MutationResult {
        $from = $laps->now();
        $runs = [];
        $judged = [];

        foreach ($preloaded as $mutant) {
            $run = $this->preparing->prepared($mutant, $batch);
            $runs = [...$runs, ...$run instanceof FreshRun ? [$run] : []];
            $judged = [...$judged, ...$run instanceof Mutant ? [$run] : []];
        }

        $slots = WorkerSlots::inPool($batch->request->pool(), strval(getmypid()));
        $commands = array_map(static fn(FreshRun $run): Command => $run->command(), $runs);
        $ended = [...$this->sideBySide($slots, $left(), $commands)];
        $verdicts = $this->verdictsOf($ended, $runs, $batch->request->memory());
        $steps = StepTimes::of($laps->lap(Step::Mutation, $from, count($runs)));
        $asked = array_keys(array_filter(
            $verdicts,
            static fn(FreshVerdict $verdict): bool => $verdict->needsControl(),
        ));
        $controlsFrom = $laps->now();
        $controlled = $this->controlled($slots, $left(), $runs, $verdicts, $asked);
        $steps = $asked === []
            ? $steps
            : $steps->and(StepTimes::of($laps->lap(Step::Controls, $controlsFrom, count($asked))));
        $batch->scan->remove();

        return $this->settled($result, $judged, $runs, $ended, $controlled, $batch->request->memory())
            ->withSteps($steps);
    }

    /**
     * The verdict of each run that ended, by its position.
     *
     * @param  array<int, Ran>       $ended
     * @param  list<FreshRun>        $runs
     * @return array<int, FreshVerdict>
     */
    private function verdictsOf(array $ended, array $runs, MemoryCap $memory): array
    {
        $verdicts = [];

        foreach ($ended as $at => $ran) {
            $run = $runs[$at];
            $guard = $run->files()->guarded();
            $verdicts[$at] = FreshVerdict::of($ran, $guard, $run->logged(), $run->covering(), $memory);
        }

        return $verdicts;
    }

    /**
     * The result, each run's mutant judged by its controlled verdict, and the evidence of each.
     *
     * @param list<Mutant>             $judged     the mutants already judged, whose run could not be written
     * @param list<FreshRun>           $runs
     * @param array<int, Ran>          $ended
     * @param array<int, FreshVerdict> $controlled
     */
    private function settled(
        MutationResult $result,
        array $judged,
        array $runs,
        array $ended,
        array $controlled,
        MemoryCap $memory,
    ): MutationResult {
        $evidence = Evidences::none();

        foreach ($controlled as $at => $verdict) {
            $run = $runs[$at];
            $mutant = $verdict->mutant($run->mutant(), $ended[$at]->duration(), $run->limit(), $memory);
            $given = $verdict->evidence($ended[$at]);
            $given = $run->isFiltered() ? $given : $given->loadingUnfiltered(WholeSuite::tests());
            $share = $result->evidence()->of($mutant->id())->share();
            $evidence = $evidence->with($mutant->id(), $given->sharing($share));
            $judged[] = $mutant;
        }

        return $result->withMutants(FoundAgain::replacing($result->mutants(), Mutants::of(...$judged)))
            ->withEvidence($evidence);
    }

    /**
     * Each verdict controlled by the unmutated run of its run where it asks
     * for one, those runs side by side; a verdict whose control never
     * started has none.
     *
     * @param  list<FreshRun>           $runs
     * @param  array<int, FreshVerdict> $verdicts
     * @param  list<int>                $asked    the positions of the runs whose verdict asks for an unmutated run
     * @return array<int, FreshVerdict>
     */
    private function controlled(
        WorkerSlots $slots,
        Seconds|Unlimited $left,
        array $runs,
        array $verdicts,
        array $asked,
    ): array {
        $commands = array_map(static fn(int $at): Command => $runs[$at]->control(), $asked);
        $ends = $this->sideBySide($slots, $left, $commands);
        $controls = array_map(NotGiven::value(...), $verdicts);

        foreach ($ends as $position => $ran) {
            $controls[$asked[$position]] = $ran;
        }

        $controlled = [];

        foreach ($verdicts as $at => $verdict) {
            $controlled[$at] = $verdict->controlledBy($controls[$at]);
        }

        return $controlled;
    }

    /**
     * These commands run side by side in these places, none started once
     * this time has run out; none where there are none.
     *
     * @param list<Command> $commands
     */
    private function sideBySide(WorkerSlots $slots, Seconds|Unlimited $left, array $commands): ProcessEnds
    {
        return $commands === []
            ? ProcessEnds::of()
            : $this->shell->sideBySide($slots, $left, ...$commands);
    }
}
