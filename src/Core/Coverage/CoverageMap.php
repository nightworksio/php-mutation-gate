<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_diff_key;
use function array_filter;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function strval;

use Traversable;

/**
 * Which tests ran which line of which file, the executable lines no test
 * ran, how long each test took, and, where the run's report states them, the
 * methods of each file some test ran and the lines they span. A test the map
 * knows need not cover anything.
 *
 * Each test is held once, and a line holds the ids of the tests that ran it,
 * so a test that ran many lines is one value however many lines hold it.
 */
final readonly class CoverageMap
{
    /**
     * @param array<string, array<int, array<string, string>>> $lines     each covered line of each file, by path and
     *                                                                    line number: the id of each test that ran it,
     *                                                                    by itself, in the order they ran it
     * @param array<string, TestId>                            $tests     every test the map knows, by id
     * @param array<string, Seconds>                           $durations each timed test's duration, by id
     * @param ByPath<ExecutedMethods>                          $methods   each file's executed methods
     * @param array<string, array<int, true>>                  $missed    each executable line of each file no test
     *                                                                    ran, by path and line number: never one some
     *                                                                    test covers
     */
    private function __construct(
        private array $lines,
        private array $tests,
        private array $durations,
        private ByPath $methods,
        private array $missed,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], ByPath::none(), []);
    }

    /**
     * A map of the tests that ran these lines, built at once; a line read
     * twice holds the tests of both, and a line no test ran is an executable
     * line the run missed.
     */
    public static function of(CoveredLine ...$covered): self
    {
        $lines = [];
        $tests = [];
        $missed = [];

        foreach ($covered as $line) {
            $file = $line->file()->value();
            $ran = [...$line];

            if ($ran === []) {
                $missed[$file][$line->line()] = true;
            }

            foreach ($ran as $test) {
                if (! array_key_exists($test, $tests)) {
                    $tests[$test] = TestId::of($test);
                }

                $lines[$file][$line->line()][$test] = $test;
            }
        }

        return new self($lines, $tests, [], ByPath::none(), self::uncovered($missed, $lines));
    }

    /** This map, with a test covering a line. */
    public function covered(Path $file, Line $line, TestId $test): self
    {
        $lines = $this->lines;
        $lines[$file->value()][$line->number()][$test->value()] = $test->value();
        $tests = $this->tests + [$test->value() => $test];
        $missed = $this->missed;

        if (array_key_exists($file->value(), $missed) && array_key_exists($line->number(), $missed[$file->value()])) {
            unset($missed[$file->value()][$line->number()]);
            $missed = array_filter($missed);
        }

        return new self($lines, $tests, $this->durations, $this->methods, $missed);
    }

    /** This map, with how long a test took. */
    public function timed(TestId $test, Seconds $duration): self
    {
        return $this->timedEach(TimedTest::of($test->value(), $duration->seconds()));
    }

    /** This map, with how long each of these tests took; a test timed twice took the later. */
    public function timedEach(TimedTest ...$timed): self
    {
        $tests = $this->tests;
        $durations = $this->durations;

        foreach ($timed as $each) {
            $test = $each->test();
            $tests += [$test->value() => $test];
            $durations[$test->value()] = $each->seconds();
        }

        return new self($this->lines, $tests, $durations, $this->methods, $this->missed);
    }

    /** This map, covering only these files: every test it knows and how long each took, and the lines of these. */
    public function onlyFor(Paths $files): self
    {
        $kept = [];
        $methods = ByPath::none();

        foreach ($files as $file) {
            $kept[$file->value()] = true;
        }

        foreach ($this->methods as $file => $executed) {
            $methods = array_key_exists($file->value(), $kept) ? $methods->with($file, $executed) : $methods;
        }

        return new self(
            array_intersect_key($this->lines, $kept),
            $this->tests,
            $this->durations,
            $methods,
            array_intersect_key($this->missed, $kept),
        );
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
            $this->tests,
            $this->durations,
            $this->methods->with($file, ExecutedMethods::of(...$held, ...$methods)),
            $this->missed,
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
        foreach ($this->lines as $file => $lines) {
            $path = Path::of(strval($file));

            foreach ($lines as $line => $tests) {
                yield CoveredLine::of($path, $line, ...array_values($tests));
            }
        }

        foreach ($this->missed as $file => $lines) {
            foreach (array_keys($lines) as $line) {
                yield CoveredLine::of(Path::of(strval($file)), $line);
            }
        }
    }

    public function tests(): TestIds
    {
        return TestIds::of(...array_values($this->tests));
    }

    public function files(): Paths
    {
        return Paths::of(...array_map(Path::of(...), array_keys($this->lines)));
    }

    public function linesCovered(Path $file): Lines
    {
        return Lines::of(...array_map(Line::of(...), array_keys($this->linesOf($file))));
    }

    /** The executable lines of a file the run that measured the map missed: no test ran them. */
    public function linesMissed(Path $file): Lines
    {
        $missed = array_key_exists($file->value(), $this->missed) ? $this->missed[$file->value()] : [];

        return Lines::of(...array_map(Line::of(...), array_keys($missed)));
    }

    public function testsCovering(Path $file, Line $line): TestIds
    {
        $lines = $this->linesOf($file);

        $ids = array_key_exists($line->number(), $lines) ? $lines[$line->number()] : [];

        return TestIds::of(...array_map(fn(string $id): TestId => $this->tests[$id], array_values($ids)));
    }

    /** Every test that covers any line of a file. */
    public function testsCoveringFile(Path $file): TestIds
    {
        $tests = [];

        foreach ($this->linesOf($file) as $covering) {
            $tests += $covering;
        }

        return TestIds::of(...array_map(fn(string $id): TestId => $this->tests[$id], array_values($tests)));
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



    /**
     * The lines no test ran, but those some test covers.
     *
     * @param  array<string, array<int, true>>                  $missed
     * @param  array<string, array<int, array<string, string>>> $lines
     * @return array<string, array<int, true>>
     */
    private static function uncovered(array $missed, array $lines): array
    {
        $uncovered = [];

        foreach ($missed as $file => $numbers) {
            $uncovered[$file] = array_diff_key($numbers, array_key_exists($file, $lines) ? $lines[$file] : []);
        }

        return array_filter($uncovered);
    }


    /** @return array<int, array<string, string>> */
    private function linesOf(Path $file): array
    {
        return array_key_exists($file->value(), $this->lines) ? $this->lines[$file->value()] : [];
    }
}
