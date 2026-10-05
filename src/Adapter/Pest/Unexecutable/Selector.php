<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_merge;
use function count;
use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Covering;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Executable;
use NightWorksIO\MutationGate\Core\Php\MatchArms;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\StatementTail;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\Unnamed;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The test files that judge a mutant Pest left uncovered. On a line that is
 * not executable: each test file that reads its value, and the test files a
 * coverage map of every file says cover each line of source that does, by
 * Pest's own selection rules; and the fallback of those that cover the
 * mutant's file. On the first line of a statement that spans more (see
 * StatementTail): the test files that cover its other lines. The scan
 * reads every file under the test directories and every file the map covers
 * or the run mutates, but chooses only the files that hold a test the map
 * names: another file there, such as a helper or a fixture, is no test file
 * Pest runs, and run as one it fails.
 */
final readonly class Selector
{
    /** The last line any file could have, so that a range from the first covers a whole file. */
    private const int WHOLE = PHP_INT_MAX;

    private function __construct(
        private Project $project,
        private Covering $coverage,
        private TestFiles $tests,
        private Paths $suite,
        private Followed $followed,
        private CoverageMap $map,
    ) {
    }

    public static function over(Project $project, Covering $coverage, Paths $mutated): self
    {
        $tests = new TestFiles($project);
        $listed = $tests->all();
        $sources = [];

        foreach ($listed as $test) {
            $sources[] = self::read($project, $test, test: true);
        }

        $map = $coverage->map($project);

        foreach ([...$map->files(), ...$mutated] as $file) {
            $sources[] = $listed->has($file) ? [] : self::read($project, $file, test: false);
        }

        $suite = $tests->holdingAny($map->tests());
        $codebase = Codebase::of(...array_merge(...$sources));

        return new self($project, $coverage, $tests, $suite, new Followed($codebase), $map);
    }

    /**
     * Which test files judge a mutant whose first changed token stands at an
     * index of its file's source: by the value it changes, on a line that is
     * not executable; by the statement's other lines, on the first line of a
     * statement that spans more; by the lines of its arms, in the head of a
     * `match`; or none, where coverage speaks for its line and the mutant
     * stays uncovered.
     */
    public function judging(Source $source, int $changed): Choice|NotGiven
    {
        $symbol = $source->symbolAt($changed);
        $tail = $symbol instanceof Executable ? StatementTail::of($source, $changed) : NotGiven::value();
        $arms = $symbol instanceof Executable ? MatchArms::of($source->tokens(), $changed) : NotGiven::value();

        return match (true) {
            ! $symbol instanceof Executable => $this->choose($symbol, $source->path()),
            $tail instanceof StatementTail => $this->running($tail->first(), $tail->last(), $source->path()),
            $arms instanceof MatchArms => $this->running($arms->first(), $arms->last(), $source->path()),
            default => NotGiven::value(),
        };
    }

    /**
     * How long a run of the tests in some files is allowed: the standard
     * mutant limit of their own time, as the map timed them, under a cap
     * (ADR-0008, decision 2).
     */
    public function limitOf(Paths $files, Seconds $cap): Seconds
    {
        $tests = $this->tests->holding($files, $this->map->tests());

        return MutantLimit::standard()->of(OwnTime::of($this->map, $tests), $cap);
    }

    /** Which test files judge a mutant of a file whose changed value a symbol names. */
    public function choose(Symbol|Unnamed $symbol, Path $file): Choice
    {
        $references = $this->followed->references($symbol);
        $reading = Paths::none();

        foreach ($references->sites() as $site) {
            $line = $site->line()->number();
            $judging = $site->isInTest()
                ? $this->ofTheSuite($site->file())
                : $this->covering($site->file(), $line, $line);

            foreach ($judging as $test) {
                $reading = $reading->with($test);
            }
        }

        return Choice::of($reading, $this->covering($file, 1, self::WHOLE), $references->isAmbiguous());
    }

    /**
     * Which test files judge a mutant on a line coverage may not mark run,
     * by the lines that run only after it: those whose tests run any of
     * them; none where no test does, and the mutant stays uncovered.
     */
    private function running(Line $first, Line $last, Path $file): Choice|NotGiven
    {
        $tests = $this->covering($file, $first->number(), $last->number());

        return count($tests) === 0 ? NotGiven::value() : Choice::of($tests, Paths::none(), ambiguous: false);
    }

    /** A file under the test directories, where it holds a test the map names. */
    private function ofTheSuite(Path $file): Paths
    {
        return $this->suite->has($file) ? Paths::of($file) : Paths::none();
    }

    /** The test files whose tests run any line from the first to the last of a file. */
    private function covering(Path $file, int $first, int $last): Paths
    {
        return $this->tests->holdingAny($this->coverage->testsCovering(
            DiskPath::of($this->project->absolute($file)),
            Line::of($first),
            Line::of($last),
        ));
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
