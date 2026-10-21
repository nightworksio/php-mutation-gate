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
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
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
use NightWorksIO\MutationGate\Core\Php\ValueDeclaration;
use NightWorksIO\MutationGate\Core\Php\ValueDeclarations;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function range;

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
        private Originals $originals,
        private Codebase $codebase,
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

        $originals = new Originals($project, $codebase);

        return new self($project, $coverage, $tests, $suite, new Followed($codebase), $map, $originals, $codebase);
    }

    /** A mutated file's original, scanned as the codebase read it; why it cannot be printed; or none. */
    public function original(Path $file): Original|CannotJudge|NotGiven
    {
        return $this->originals->of($file);
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
     * A map with the lines of each value these files declare covered by the
     * tests of the test files one run judges its mutants by (see Choice), each
     * timed as the map of every file timed it, where it did not already, so
     * Pest runs those mutants as it runs any covered one. A value whose reads
     * the scan cannot follow, or that nothing reads, is left as the map had
     * it, and a trial judges its mutants (ADR-0037).
     */
    public function readCoverage(Paths $files, CoverageMap $onto): CoverageMap
    {
        $read = $onto;

        foreach ($files as $file) {
            $source = $this->codebase->source($file);

            foreach ($source instanceof Source ? ValueDeclarations::in($source) : [] as $declared) {
                $read = $this->reading($read, $file, $declared);
            }
        }

        return $read;
    }

    /**
     * How long a run of the tests in some files is allowed: the standard
     * mutant limit of their own time, as the map timed them, kept between
     * the bounds (ADR-0008, decision 2).
     */
    public function limitOf(Paths $files, LimitBounds $bounds): Seconds
    {
        $tests = $this->tests->holding($files, $this->map);

        return MutantLimit::standard()->of(OwnTime::of($this->map, $tests), $bounds);
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
     * A map with the lines of one value covered by the tests one run judges
     * its mutants by, each timed; the map as it is where no run can.
     */
    private function reading(CoverageMap $map, Path $file, ValueDeclaration $declared): CoverageMap
    {
        $run = $this->choose($declared->symbol(), $file)->oneRun();
        $tests = $run instanceof Paths ? $this->tests->holding($run, $this->map) : TestIds::none();

        if (count($tests) === 0) {
            return $map;
        }

        $read = $map;

        foreach (range($declared->first()->number(), $declared->last()->number()) as $line) {
            $read = $read->covered($file, Line::of($line), ...$tests);
        }

        return $read->timedEach(...$this->timed($tests, $map));
    }

    /**
     * How long each of these tests took, as the map of every file timed it,
     * where a map does not time it already.
     *
     * @return list<TimedTest>
     */
    private function timed(TestIds $tests, CoverageMap $timing): array
    {
        $timed = [];

        foreach ($tests as $test) {
            $duration = $this->map->durationOf($test);

            if ($duration instanceof Seconds && ! $timing->durationOf($test) instanceof Seconds) {
                $timed[] = TimedTest::of($test->value(), $duration->seconds());
            }
        }

        return $timed;
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
