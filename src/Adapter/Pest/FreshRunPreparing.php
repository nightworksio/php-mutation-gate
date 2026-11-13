<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
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
        $printed = Printed::checkable($this->project, $mutant);
        $placed = $printed instanceof CannotJudge
            ? $printed
            : $this->placement->placed($mutant, $printed->mutant(), $listing);

        return match (true) {
            $printed instanceof CannotJudge => $printed,
            $placed instanceof CannotJudge => $placed,
            default => $this->besidePlaced($printed, $placed, $mutant->id()->value()),
        };
    }

    /** The log, the unmutated copy and the control's guard beside a placed mutant. */
    private function besidePlaced(Checkable $printed, PlacedMutant $placed, string $id): FreshRunFiles|CannotJudge
    {
        $log = $this->placement->fresh(sprintf('%s/%s', $id, JUnitLog::NAME));
        $copy = $log instanceof CannotJudge ? $log : $this->unmutated($printed, $placed, $id);
        $guard = $copy instanceof CannotJudge ? $copy : $this->placement->fresh(sprintf(self::CONTROL_GUARD, $id));

        return match (true) {
            $log instanceof CannotJudge => $log,
            $copy instanceof CannotJudge => $copy,
            $guard instanceof CannotJudge => $guard,
            default => new FreshRunFiles($placed, $log, $copy, $guard),
        };
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
        $serving = fn(string $served, string $guarded, string ...$logging): Command => $batch->scan->onto(
            Invocation::of($this->project)
                ->judging($tests, $judgedBy, $request->withheld(), ...$suites, ...$logging)
                ->startingPhpWith(...$this->placement->startingPhp($batch->override))
                ->with([
                    Variable::Mutant->value => $placed->original(),
                    Variable::Mutated->value => $served,
                    Variable::Guard->value => $guarded,
                ]),
        )->within($limit);

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
     * The copy of a mutant's file its control serves, written in the
     * mutant's own directory: the file as Pest prints it, or the file itself
     * where Pest's print of it is not known.
     */
    private function unmutated(Checkable $printed, PlacedMutant $placed, string $id): string|CannotJudge
    {
        $original = $printed->original();

        return $original instanceof Contents
            ? $this->placement->written(sprintf(self::UNMUTATED, $id), $original->text())
            : $placed->original();
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
