<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use function file;
use function file_get_contents;
use function is_array;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\Runner\StartUp;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;

/**
 * What a framework reads and writes for one mutant, in its own directory of
 * the adapter's: the tests it runs, as its selection lists them, one to a
 * line; the results its recorder records and the guard the wrapper and the
 * recorder write, each empty to begin with; the file the override serves,
 * and the mutated file it serves in its place. A run of no test, timing a
 * mutant's start-up, has files of its own: no test listed, and the file
 * unchanged as its mutant.
 *
 * @internal
 */
final readonly class MutantFiles
{
    private const string RESULTS = 'results.txt';

    private const string GUARD = 'guard.txt';

    private const string MUTATED = 'mutant.php';

    private const string UNREAD = 'The gate cannot read %s, which a run of no test serves unchanged as its mutant.';

    private function __construct(
        private string $selection,
        private string $results,
        private string $guard,
        private string $original,
        private string $mutated,
    ) {
    }

    /** The files for a run of the tests this selection lists. */
    public static function selecting(Project $project, MadeMutant $mutant, Selection $selection): self|CannotJudge
    {
        return self::written(
            $project,
            $mutant->id()->value(),
            $mutant->location()->file(),
            $mutant->mutated(),
            $selection,
        );
    }

    /**
     * The files for a run of no test, started as a mutant's own run of this
     * file is, its mutant the file unchanged, and selecting as this
     * selection does, so that only the run's own narrowing selects its tests.
     */
    public static function startingUp(Project $project, Path $file, Selection $selection): self|CannotJudge
    {
        $absolute = $project->absolute($file);
        $contents = is_file($absolute) ? file_get_contents($absolute) : false;

        return $contents === false
            ? CannotJudge::because(sprintf(self::UNREAD, $file->value()))
            : self::written($project, StartUp::DIRECTORY, $file, Contents::of($contents), $selection);
    }

    /** The option that has the framework select the tests from their file. */
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

    /** What the run said of itself in the guard file; a file that is not there says nothing was served. */
    public function guarded(): Guard
    {
        $lines = is_file($this->guard) ? file($this->guard, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return Guard::of(is_array($lines) ? $lines : []);
    }

    public function original(): string
    {
        return $this->original;
    }

    public function mutated(): string
    {
        return $this->mutated;
    }

    /** @param string $directory the run's own, among the adapter's files */
    private static function written(
        Project $project,
        string $directory,
        Path $file,
        Contents $mutant,
        Selection $selection,
    ): self|CannotJudge {
        $selected = $project->written(sprintf('%s/%s', $directory, $selection->file()), $selection->contents());
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
