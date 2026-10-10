<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_key_exists;
use function array_map;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use Traversable;

/**
 * Which tests ran which line of which file, the executable lines no test
 * ran, how long each test took, and, where the run's report states them, the
 * methods of each file some test ran and the lines they span. A test the map
 * knows need not cover anything.
 *
 * Each test is held once, and a line holds the places of the tests that ran
 * it in the map's list of tests (see LineTests), so a test that ran many
 * lines is one value however many lines hold it.
 */
final readonly class CoverageMap
{
    /**
     * @param array<string, Seconds>  $durations each timed test's duration, by id
     * @param ByPath<ExecutedMethods> $methods   each file's executed methods
     */
    private function __construct(private LineTests $lines, private array $durations, private ByPath $methods)
    {
    }

    public static function empty(): self
    {
        return new self(LineTests::none(), [], ByPath::none());
    }

    /**
     * A map of the tests that ran these lines, built at once; a line read
     * twice holds the tests of both, and a line no test ran is an executable
     * line the run missed.
     */
    public static function of(CoveredLine ...$covered): self
    {
        return new self(LineTests::of(...$covered), [], ByPath::none());
    }

    /** A map of lines held as its file writes them, with each timed test's duration, by id. */
    public static function placed(LineTests $lines, TimedTest ...$timed): self
    {
        return new self($lines, [], ByPath::none())->timedEach(...$timed);
    }

    /** This map, with a test covering a line. */
    public function covered(Path $file, Line $line, TestId $test): self
    {
        return new self($this->lines->covered($file, $line, $test), $this->durations, $this->methods);
    }

    /** This map, with how long a test took. */
    public function timed(TestId $test, Seconds $duration): self
    {
        return $this->timedEach(TimedTest::of($test->value(), $duration->seconds()));
    }

    /** This map, with how long each of these tests took; a test timed twice took the later. */
    public function timedEach(TimedTest ...$timed): self
    {
        $durations = $this->durations;

        foreach ($timed as $each) {
            $durations[$each->test()->value()] = $each->seconds();
        }

        $tests = array_map(static fn(TimedTest $each): TestId => $each->test(), $timed);

        return new self($this->lines->knowing(...$tests), $durations, $this->methods);
    }

    /** This map, covering only these files: every test it knows and how long each took, and the lines of these. */
    public function onlyFor(Paths $files): self
    {
        $methods = ByPath::none();

        foreach ($this->methods as $file => $executed) {
            $methods = $files->has($file) ? $methods->with($file, $executed) : $methods;
        }

        return new self($this->lines->onlyFor($files), $this->durations, $methods);
    }

    /** This map, with these methods of a file executed, after any it already holds for it. */
    public function executing(Path $file, ExecutedMethod ...$methods): self
    {
        if ($methods === []) {
            return $this;
        }

        $held = $this->methods->at($file, ExecutedMethods::none());

        return new self(
            $this->lines,
            $this->durations,
            $this->methods->with($file, ExecutedMethods::of(...$held, ...$methods)),
        );
    }

    /**
     * The methods of each file some test ran, for the files whose report
     * states them.
     *
     * @return ByPath<ExecutedMethods>
     */
    public function methods(): ByPath
    {
        return $this->methods;
    }

    /**
     * Every executable line of every file the map knows: each covered line
     * with the tests that ran it, file by file in the order they were first
     * covered, then each line the run missed, with none.
     *
     * @return Traversable<int, CoveredLine>
     */
    public function lines(): Traversable
    {
        return $this->lines->lines();
    }

    public function tests(): TestIds
    {
        return $this->lines->tests();
    }

    public function files(): Paths
    {
        return $this->lines->files();
    }

    /**
     * The executable lines of a file: each covered one with a name for the
     * set of tests that ran it, the same for the same set anywhere in this
     * map, and those no test ran.
     */
    public function lineSets(Path $file): LineSets
    {
        return $this->lines->setsOn($file);
    }

    public function linesCovered(Path $file): Lines
    {
        return $this->lines->coveredIn($file);
    }

    public function testsCovering(Path $file, Line $line): TestIds
    {
        return $this->lines->running($file, $line, $line);
    }

    /** Every test that covers any line of a file from the first to the last, each once. */
    public function testsCoveringSpan(Path $file, Line $first, Line $last): TestIds
    {
        return $this->lines->running($file, $first, $last);
    }

    /** Every test that covers any line of a file. */
    public function testsCoveringFile(Path $file): TestIds
    {
        return $this->lines->runningFile($file);
    }

    public function durationOf(TestId $test): Seconds|Unmeasured
    {
        return array_key_exists($test->value(), $this->durations)
            ? $this->durations[$test->value()]
            : Unmeasured::duration();
    }

    /**
     * How long the whole suite takes, test after test, as the map timed it:
     * what a runner's opening run costs before it has measured one of its
     * own. A test the map did not time adds nothing.
     */
    public function suiteDuration(): Seconds
    {
        $seconds = 0.0;

        foreach ($this->durations as $duration) {
            $seconds += $duration->seconds();
        }

        return Seconds::of($seconds);
    }
}
