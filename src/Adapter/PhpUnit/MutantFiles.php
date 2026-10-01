<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;

/**
 * What PHPUnit reads and writes for one mutant, in its own directory of the
 * adapter's: the tests it runs, by their ids or by their files, one to a
 * line; the results the extension records and the guard the wrapper and the
 * extension write, each empty to begin with; the file the override serves,
 * and the mutated file it serves in its place.
 */
final readonly class MutantFiles
{
    private const string RESULTS = 'results.txt';

    private const string GUARD = 'guard.txt';

    private const string MUTATED = 'mutant.php';

    private function __construct(
        private string $selection,
        private string $results,
        private string $guard,
        private string $original,
        private string $mutated,
    ) {
    }

    /** The files for a run of these tests, selected by their ids. */
    public static function selectingTests(Project $project, MadeMutant $mutant, TestIds $tests): self|CannotJudge
    {
        $ids = array_map(static fn(TestId $test): string => $test->value(), [...$tests]);

        return self::written($project, $mutant, Selection::Ids, $ids);
    }

    /** The files for a run of every test in these test files. */
    public static function selectingFiles(Project $project, MadeMutant $mutant, Paths $files): self|CannotJudge
    {
        $onDisk = array_map($project->absolute(...), [...$files]);

        return self::written($project, $mutant, Selection::Files, $onDisk);
    }

    /** The option that has PHPUnit select the tests from their file. */
    public function selection(): string
    {
        return $this->selection;
    }

    public function results(): string
    {
        return $this->results;
    }

    public function guard(): string
    {
        return $this->guard;
    }

    public function original(): string
    {
        return $this->original;
    }

    public function mutated(): string
    {
        return $this->mutated;
    }

    /** @param list<string> $lines what the selection's file lists */
    private static function written(
        Project $project,
        MadeMutant $mutant,
        Selection $selection,
        array $lines,
    ): self|CannotJudge {
        $id = $mutant->id()->value();
        $listed = sprintf('%s%s', implode(Selection::LINE_END, $lines), Selection::LINE_END);
        $selected = $project->written(sprintf('%s/%s', $id, $selection->value), $listed);
        $results = $project->written(sprintf('%s/%s', $id, self::RESULTS), '');
        $guard = $project->written(sprintf('%s/%s', $id, self::GUARD), '');
        $mutated = $project->written(sprintf('%s/%s', $id, self::MUTATED), $mutant->mutated()->text());

        return match (true) {
            $selected instanceof CannotJudge => $selected,
            $results instanceof CannotJudge => $results,
            $guard instanceof CannotJudge => $guard,
            $mutated instanceof CannotJudge => $mutated,
            default => new self(
                $selection->option($selected),
                $results,
                $guard,
                $project->absolute($mutant->location()->file()),
                $mutated,
            ),
        };
    }
}
