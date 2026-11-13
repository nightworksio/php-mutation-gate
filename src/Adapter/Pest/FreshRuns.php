<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;

use Closure;

use function count;
use function getmypid;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\Native\PlacedMutant;
use NightWorksIO\MutationGate\Core\Native\Selection as NativeSelection;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\JUnitLog;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;
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

    /** The file in a mutant's own directory that lists its covering tests, one id a line. */
    private const string COVERING = 'covering.txt';

    /** Where a mutant's fresh run writes its JUnit log, in the mutant's own directory. */
    private const string LOG = '%s/%s';

    /** Where the unmutated copy a mutant's control serves is written, in the mutant's own directory. */
    private const string UNMUTATED = '%s/unmutated.php';

    /** Where the wrapper of a mutant's control writes what it served, in the mutant's own directory. */
    private const string CONTROL_GUARD = '%s/control-guard.txt';

    /** The project's test files, by the classes Pest declares for them. */
    private TestFiles $tests;

    public function __construct(
        private Project $project,
        private MutantPlacement $placement,
        private Shell $shell,
        private CapFiles $files,
        private Patching $patching,
        private LimitBounds $bounds,
    ) {
        $this->tests = new TestFiles($project);
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

        if ($override instanceof CannotJudge || $scan instanceof CannotJudge) {
            $why = Reason::that($override instanceof CannotJudge ? $override->why() : $scan->why());

            return $result->withMutants(FoundAgain::replacing($result->mutants(), Mutants::of(...array_map(
                static fn(Mutant $mutant): Mutant => Interpretation::unjudged($mutant, $why),
                $preloaded,
            ))));
        }

        $from = $laps->now();
        $map = $coverage->map($this->project);
        $suite = Suite::of($coverage->tests());
        $bounds = $request->pool()->bounding($this->bounds);
        $runs = [];
        $judged = [];

        foreach ($preloaded as $mutant) {
            $run = $this->prepared($mutant, $request, $coverage, $map, $suite, $bounds, $override, $scan);
            $runs = [...$runs, ...$run instanceof FreshRun ? [$run] : []];
            $judged = [...$judged, ...$run instanceof Mutant ? [$run] : []];
        }

        $slots = WorkerSlots::inPool($request->pool(), strval(getmypid()));
        $commands = array_map(static fn(FreshRun $run): Command => $run->command(), $runs);
        $ends = $this->sideBySide($slots, $left(), $commands);
        $ended = [];
        $verdicts = [];

        foreach ($ends as $at => $ran) {
            $ended[$at] = $ran;
            $verdicts[$at] = FreshVerdict::of(
                $ran,
                $runs[$at]->files()->guarded(),
                $runs[$at]->logged(),
                $runs[$at]->covering(),
                $request->memory(),
            );
        }

        $steps = StepTimes::of($laps->lap(Step::Mutation, $from, count($runs)));
        $asked = array_keys(array_filter(
            $verdicts,
            static fn(FreshVerdict $verdict): bool => $verdict->needsControl(),
        ));
        $controlsFrom = $laps->now();
        $controls = $this->controlled($slots, $left(), $runs, $asked);
        $steps = $asked === []
            ? $steps
            : $steps->and(StepTimes::of($laps->lap(Step::Controls, $controlsFrom, count($asked))));
        $scan->remove();
        $evidence = Evidences::none();

        foreach ($verdicts as $at => $verdict) {
            $run = $runs[$at];
            $final = $verdict->controlledBy($controls[$at] ?? NotGiven::value());
            $mutant = $final->mutant($run->mutant(), $ended[$at]->duration(), $run->limit(), $request->memory());
            $given = $final->evidence($ended[$at]);
            $given = $run->isFiltered() ? $given : $given->loadingUnfiltered(WholeSuite::tests());
            $share = $result->evidence()->of($mutant->id())->share();
            $evidence = $evidence->with($mutant->id(), $given->sharing($share));
            $judged[] = $mutant;
        }

        return $result->withMutants(FoundAgain::replacing($result->mutants(), Mutants::of(...$judged)))
            ->withEvidence($evidence)
            ->withSteps($steps);
    }

    /** Whether a run left any of these mutants unjudged because its own process had loaded its file first. */
    public static function findAny(Mutants $mutants): bool
    {
        return self::preloadedIn($mutants) !== [];
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
     * A mutant's fresh run, its files written: the mutant as Pest prints it,
     * served in its file's place, the guard and the JUnit log; and its
     * control, the same run serving the file as Pest prints it unmutated,
     * through the same override; or the mutant unjudged, saying why one
     * cannot be.
     */
    private function prepared(
        Mutant $mutant,
        MutationRequest $request,
        Covering $coverage,
        CoverageMap $map,
        Suite $suite,
        LimitBounds $bounds,
        string $override,
        MemoryScan $scan,
    ): FreshRun|Mutant {
        $location = $mutant->location();
        $covering = $coverage->testsCovering(
            DiskPath::of($this->project->absolute($location->file())),
            $location->start(),
            $location->last(),
        );
        $selection = $this->patching->selecting($covering, $suite);
        $printed = Printed::checkable($this->project, $mutant);
        $files = $printed instanceof CannotJudge ? $printed : $this->placement->placed(
            $mutant,
            $printed->mutant(),
            $this->listing($covering, $selection->fits()),
        );
        $id = $mutant->id()->value();
        $log = $files instanceof CannotJudge
            ? $files
            : $this->placement->fresh(sprintf(self::LOG, $id, JUnitLog::NAME));
        $copy = $printed instanceof CannotJudge || $files instanceof CannotJudge || $log instanceof CannotJudge
            ? $log
            : $this->unmutated($printed, $files, $id);
        $guard = $copy instanceof CannotJudge ? $copy : $this->placement->fresh(sprintf(self::CONTROL_GUARD, $id));

        if ($files instanceof CannotJudge || $copy instanceof CannotJudge || $guard instanceof CannotJudge) {
            return Interpretation::unjudged($mutant, Reason::that(match (true) {
                $files instanceof CannotJudge => $files->why(),
                $copy instanceof CannotJudge => $copy->why(),
                default => $guard->why(),
            }));
        }

        $limit = MutantLimit::standard()->of(OwnTime::of($map, $covering), $bounds);
        $judgedBy = $selection->fits() ? $selection->filter() : $request->judgedBy();
        $tests = $selection->fits() ? $this->tests->naming($selection->classes()) : Paths::none();
        $suites = PhpUnitOption::inSuites($request->narrowing()->suitesFor($judgedBy));
        $serving = fn(string $served, string $guarded, string ...$logging): Command => $scan->onto(
            Invocation::of($this->project)
                ->judging($tests, $judgedBy, $request->withheld(), ...$suites, ...$logging)
                ->startingPhpWith(...$this->placement->startingPhp($override))
                ->with([
                    Variable::Mutant->value => $files->original(),
                    Variable::Mutated->value => $served,
                    Variable::Guard->value => $guarded,
                ]),
        )->within($limit);

        return FreshRun::of(
            $mutant,
            $covering,
            $files,
            $serving($files->mutated(), $files->guard(), sprintf('%s=%s', PhpUnitOption::LogJunit->value, $log)),
            $serving($copy, $guard),
            $log,
            $limit,
            $selection->fits(),
        );
    }

    /**
     * The copy of a mutant's file its control serves, written in the
     * mutant's own directory: the file as Pest prints it, or the file itself
     * where Pest's print of it is not known.
     */
    private function unmutated(Checkable $printed, PlacedMutant $files, string $id): string|CannotJudge
    {
        $original = $printed->original();

        return $original instanceof Contents
            ? $this->placement->written(sprintf(self::UNMUTATED, $id), $original->text())
            : $files->original();
    }

    /**
     * What a mutant's own directory lists of its covering tests, one id a
     * line: exactly those where its run selects them by a filter, and those
     * whose files it runs whole otherwise.
     */
    private function listing(TestIds $covering, bool $filtered): NativeSelection
    {
        $lines = array_map(static fn(TestId $test): string => $test->value(), [...$covering]);
        $option = sprintf('%s=%%s', PhpUnitOption::TestIdFilterFile->value);

        return $filtered
            ? NativeSelection::exactly(self::COVERING, $lines, $option)
            : NativeSelection::byFile(self::COVERING, $lines, $option);
    }

    /**
     * The unmutated runs of those runs whose verdict asks for one, side by
     * side, by the position of their run; one never started is not among
     * them.
     *
     * @param  list<FreshRun> $runs
     * @param  list<int>      $asked the positions of the runs whose verdict asks for an unmutated run
     * @return array<int, Ran>
     */
    private function controlled(WorkerSlots $slots, Seconds|Unlimited $left, array $runs, array $asked): array
    {
        $commands = array_map(static fn(int $at): Command => $runs[$at]->control(), $asked);
        $ends = $this->sideBySide($slots, $left, $commands);
        $controls = [];

        foreach ($ends as $position => $ran) {
            $controls[$asked[$position]] = $ran;
        }

        return $controls;
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
            : $this->shell->sideBySide($slots, $left, ...array_values($commands));
    }
}
