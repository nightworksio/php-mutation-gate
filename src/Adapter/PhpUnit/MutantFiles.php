<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_map;
use function file_get_contents;
use function implode;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
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
 * and the mutated file it serves in its place. A run of no test, timing a
 * mutant's start-up, has files of its own: no test listed, and the file
 * unchanged as its mutant.
 */
final readonly class MutantFiles
{
    private const string RESULTS = 'results.txt';

    private const string GUARD = 'guard.txt';

    private const string MUTATED = 'mutant.php';

    /** The directory of a run of no test, among the mutants' own, each named by its id in hex. */
    private const string START_UP = 'start-up';

    private const string UNREAD = 'The gate cannot read %s, which a run of no test serves unchanged as its mutant.';

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

        return self::written(
            $project,
            $mutant->id()->value(),
            $mutant->location()->file(),
            $mutant->mutated(),
            Selection::Ids,
            $ids,
        );
    }

    /** The files for a run of every test in these test files. */
    public static function selectingFiles(Project $project, MadeMutant $mutant, Paths $files): self|CannotJudge
    {
        $onDisk = array_map($project->absolute(...), [...$files]);

        return self::written(
            $project,
            $mutant->id()->value(),
            $mutant->location()->file(),
            $mutant->mutated(),
            Selection::Files,
            $onDisk,
        );
    }

    /**
     * The files for a run of no test, started as a mutant's own run of this
     * file is, its mutant the file unchanged, and listing no test, so that
     * only the run's own narrowing selects its tests.
     */
    public static function startingUp(Project $project, Path $file): self|CannotJudge
    {
        $absolute = $project->absolute($file);
        $contents = is_file($absolute) ? file_get_contents($absolute) : false;

        return $contents === false
            ? CannotJudge::because(sprintf(self::UNREAD, $file->value()))
            : self::written($project, self::START_UP, $file, Contents::of($contents), Selection::Ids, []);
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

    /**
     * @param string       $directory the run's own, among the adapter's files
     * @param list<string> $lines     what the selection's file lists
     */
    private static function written(
        Project $project,
        string $directory,
        Path $file,
        Contents $mutant,
        Selection $selection,
        array $lines,
    ): self|CannotJudge {
        $listed = sprintf('%s%s', implode(Selection::LINE_END, $lines), Selection::LINE_END);
        $selected = $project->written(sprintf('%s/%s', $directory, $selection->value), $listed);
        $results = $project->written(sprintf('%s/%s', $directory, self::RESULTS), '');
        $guard = $project->written(sprintf('%s/%s', $directory, self::GUARD), '');
        $mutated = $project->written(sprintf('%s/%s', $directory, self::MUTATED), $mutant->text());

        return match (true) {
            $selected instanceof CannotJudge => $selected,
            $results instanceof CannotJudge => $results,
            $guard instanceof CannotJudge => $guard,
            $mutated instanceof CannotJudge => $mutated,
            default => new self(
                $selection->option($selected),
                $results,
                $guard,
                $project->absolute($file),
                $mutated,
            ),
        };
    }
}
