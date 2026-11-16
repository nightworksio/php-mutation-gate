<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\Native\PlacedMutant;
use NightWorksIO\MutationGate\Core\Native\Selection as NativeSelection;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Test\JUnitLog;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * Each mutant's fresh run under `pest.preloaded: fresh`, its files written
 * (ADR-0148): the mutant as Pest prints it, served in its file's place, the
 * guard and the JUnit log; and its control, the same run serving the file as
 * Pest prints it unmutated, through the same override.
 *
 * @internal
 */
final readonly class FreshRunPreparing
{
    /** The file in a mutant's own directory that lists its covering tests, one id a line. */
    private const string COVERING = 'covering.txt';

    /** Where the unmutated copy a mutant's control serves is written, in the mutant's own directory. */
    private const string UNMUTATED = '%s/unmutated.php';

    /** Where the wrapper of a mutant's control writes what it served, in the mutant's own directory. */
    private const string CONTROL_GUARD = '%s/control-guard.txt';

    /** The project's test files, by the classes Pest declares for them. */
    private TestFiles $tests;

    public function __construct(
        private Project $project,
        private MutantPlacement $placement,
        private Patching $patching,
    ) {
        $this->tests = new TestFiles($project);
    }

    /** A mutant's fresh run, its files written; or the mutant unjudged, saying why one cannot be. */
    public function prepared(Mutant $mutant, FreshBatch $batch): FreshRun|Mutant
    {
        $location = $mutant->location();
        $covering = $batch->coverage->testsCovering(
            DiskPath::of($this->project->absolute($location->file())),
            $location->start(),
            $location->last(),
        );
        $selection = $this->patching->selecting($covering, $batch->suite);
        $files = $this->written($mutant, $this->listing($covering, $selection->fits()));

        return $files instanceof CannotJudge
            ? Interpretation::unjudged($mutant, Reason::that($files->why()))
            : $this->run($mutant, $covering, $selection, $files, $batch);
    }

    /** The files of a mutant's fresh run, written in its own directory. */
    private function written(Mutant $mutant, NativeSelection $listing): FreshRunFiles|CannotJudge
    {
        $original = Printed::at($this->project, $mutant->location()->file());

        if ($original instanceof CannotJudge) {
            return $original;
        }

        $printed = Printed::patched($original, $mutant);

        if ($printed instanceof CannotJudge) {
            return $printed;
        }

        $placed = $this->placement->placed($mutant, $printed, $listing);

        return $placed instanceof CannotJudge
            ? $placed
            : $this->besidePlaced($original, $placed, $mutant->id()->value());
    }

    /** The log, the unmutated copy and the control's guard beside a placed mutant. */
    private function besidePlaced(Contents $original, PlacedMutant $placed, string $id): FreshRunFiles|CannotJudge
    {
        $log = $this->placement->fresh(sprintf('%s/%s', $id, JUnitLog::NAME));

        if ($log instanceof CannotJudge) {
            return $log;
        }

        $copy = $this->placement->written(sprintf(self::UNMUTATED, $id), $original->text());

        if ($copy instanceof CannotJudge) {
            return $copy;
        }

        $guard = $this->placement->fresh(sprintf(self::CONTROL_GUARD, $id));

        return $guard instanceof CannotJudge ? $guard : new FreshRunFiles($placed, $log, $copy, $guard);
    }

    /** A mutant's fresh run and its control, over its written files. */
    private function run(
        Mutant $mutant,
        TestIds $covering,
        Selection $selection,
        FreshRunFiles $files,
        FreshBatch $batch,
    ): FreshRun {
        $request = $batch->request;
        $limit = MutantLimit::standard()->of(OwnTime::of($batch->map, $covering), $batch->bounds);
        $judgedBy = $selection->fits() ? $selection->filter() : $request->judgedBy();
        $tests = $selection->fits() ? $this->tests->naming($selection->classes()) : Paths::none();
        $suites = PhpUnitOption::inSuites($request->narrowing()->suitesFor($judgedBy));
        $placed = $files->placed;
        $logging = sprintf('%s=%s', PhpUnitOption::LogJunit->value, $files->log);
        $serving = fn(string $served, string $guarded, string ...$logging): Command => $batch->children->onto(
            $batch->scan->onto(
                Invocation::of($this->project)
                ->judging($tests, $judgedBy, $request->withheld(), ...$suites, ...$logging)
                ->startingPhpWith(...$this->placement->startingPhp($batch->override))
                ->with([
                    Variable::Mutant->value => $placed->original(),
                    Variable::Mutated->value => $served,
                    Variable::Guard->value => $guarded,
                ]),
            )->within($limit),
        );

        return FreshRun::of(
            $mutant,
            $covering,
            $placed,
            $serving($placed->mutated(), $placed->guard(), $logging),
            $serving($files->unmutated, $files->controlGuard),
            $files->log,
            $limit,
            $selection->fits(),
        );
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
}
