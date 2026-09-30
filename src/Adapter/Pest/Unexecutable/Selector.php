<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\Symbol;

/**
 * The test files that judge a mutant of a line that is not executable: each
 * test file that reads its value, and the test files the run's own coverage
 * map says cover each line of source that does, by Pest's own selection
 * rules; and the fallback of those that cover the mutant's file. The scan
 * reads the test files and every file the map covers or the run mutates.
 */
final readonly class Selector
{
    /** The last line any file could have, so that a range from the first covers a whole file. */
    private const int WHOLE = PHP_INT_MAX;

    private function __construct(
        private Project $project,
        private CoverageFile $coverage,
        private Paths $tests,
        private Codebase $codebase,
    ) {
    }

    public static function over(Project $project, CoverageFile $coverage, Paths $mutated): self
    {
        $tests = TestFiles::in($project);
        $sources = [];

        foreach ($tests as $test) {
            $sources = [...$sources, ...self::read($project, $test, test: true)];
        }

        foreach ([...$coverage->map($project)->files(), ...$mutated] as $file) {
            $sources = $tests->has($file) ? $sources : [...$sources, ...self::read($project, $file, test: false)];
        }

        return new self($project, $coverage, $tests, Codebase::of(...$sources));
    }

    /** Which test files judge a mutant of a file whose changed value a symbol names. */
    public function choose(Symbol $symbol, Path $file): Choice
    {
        $references = $this->codebase->references($symbol);
        $reading = Paths::none();

        foreach ($references->sites() as $site) {
            $line = $site->line()->number();
            $judging = $site->isInTest() ? Paths::of($site->file()) : $this->covering($site->file(), $line, $line);

            foreach ($judging as $test) {
                $reading = $reading->with($test);
            }
        }

        return Choice::of($reading, $this->covering($file, 1, self::WHOLE), $references->isAmbiguous());
    }

    /** The test files whose tests run any line from the first to the last of a file, or all where Pest cannot say. */
    private function covering(Path $file, int $first, int $last): Paths
    {
        $selection = Selection::of($this->coverage->testsCovering($this->project->absolute($file), $first, $last));

        return match (true) {
            $selection->count() === 0 => Paths::none(),
            $selection->fits() => TestFiles::naming($this->tests, $selection->classes()),
            default => $this->tests,
        };
    }

    /**
     * The file at a path of the project, read for the scan, if it is there.
     *
     * @return list<Source>
     */
    private static function read(Project $project, Path $file, bool $test): array
    {
        $path = $project->absolute($file);
        $text = is_file($path) ? file_get_contents($path) : false;

        return is_string($text) ? [Source::read($file, Contents::of($text), $test)] : [];
    }
}
