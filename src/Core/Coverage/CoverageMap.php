<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;

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
 * Which tests ran which line of which file, and how long each test took. A
 * test the map knows need not cover anything.
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
     */
    private function __construct(private array $lines, private array $tests, private array $durations)
    {
    }

    public static function empty(): self
    {
        return new self([], [], []);
    }

    /** A map of the tests that ran these lines, built at once; a line read twice holds the tests of both. */
    public static function of(CoveredLine ...$covered): self
    {
        $lines = [];
        $tests = [];

        foreach ($covered as $line) {
            $file = $line->file()->value();

            foreach ($line as $test) {
                if (! array_key_exists($test, $tests)) {
                    $tests[$test] = TestId::of($test);
                }

                $lines[$file][$line->line()][$test] = $test;
            }
        }

        return new self($lines, $tests, []);
    }

    /** This map, with a test covering a line. */
    public function covered(Path $file, Line $line, TestId $test): self
    {
        $lines = $this->lines;
        $lines[$file->value()][$line->number()][$test->value()] = $test->value();

        return new self($lines, $this->tests + [$test->value() => $test], $this->durations);
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

        return new self($this->lines, $tests, $durations);
    }

    /** This map, covering only these files: every test it knows and how long each took, and the lines of these. */
    public function onlyFor(Paths $files): self
    {
        $kept = [];

        foreach ($files as $file) {
            $kept[$file->value()] = true;
        }

        return new self(array_intersect_key($this->lines, $kept), $this->tests, $this->durations);
    }

    /**
     * Every covered line of every file, with the tests that ran it, file by
     * file in the order they were first covered.
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

    public function testsCovering(Path $file, Line $line): TestIds
    {
        $lines = $this->linesOf($file);

        return array_key_exists($line->number(), $lines) ? $this->testsOf($lines[$line->number()]) : TestIds::none();
    }

    /** Every test that covers any line of a file. */
    public function testsCoveringFile(Path $file): TestIds
    {
        $tests = [];

        foreach ($this->linesOf($file) as $covering) {
            $tests += $covering;
        }

        return $this->testsOf($tests);
    }

    public function durationOf(TestId $test): Seconds|Unmeasured
    {
        return array_key_exists($test->value(), $this->durations)
            ? $this->durations[$test->value()]
            : Unmeasured::duration();
    }

    /** @param array<string, string> $ids */
    private function testsOf(array $ids): TestIds
    {
        return TestIds::of(...array_map(fn(string $id): TestId => $this->tests[$id], array_values($ids)));
    }

    /** @return array<int, array<string, string>> */
    private function linesOf(Path $file): array
    {
        return array_key_exists($file->value(), $this->lines) ? $this->lines[$file->value()] : [];
    }
}
