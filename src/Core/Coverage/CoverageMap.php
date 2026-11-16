<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_filter;
use function array_map;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
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
    private function __construct(
        private LineTests $lines,
        private TestDurations $durations,
        private Executed $executed,
    ) {
    }

    /**
     * A map of the tests that ran these lines, built at once; a line read
     * twice holds the tests of both, and a line no test ran is an executable
     * line the run missed.
     */
    public static function of(CoveredLine ...$covered): self
    {
        return new self(LineTests::of(...$covered), TestDurations::none(), Executed::unrecorded());
    }

    /** A map of lines held as its file writes them, with each timed test's duration, by id. */
    public static function placed(LineTests $lines, TimedTest ...$timed): self
    {
        return new self($lines, TestDurations::none(), Executed::unrecorded())->timedEach(...$timed);
    }

    /**
     * This map with another's lines and tests' durations laid beside its
     * own, as the parts of a run that each recorded what their tests
     * executed make one map (ADR-0135); a line both hold keeps the tests of
     * both, and a test both timed keeps the longer time.
     */
    public function and(self $other): self
    {
        $map = $this;

        foreach ($other->lines() as $line) {
            $map = $map->covered(
                $line->file(),
                Line::of($line->line()),
                ...array_map(TestId::of(...), [...$line]),
            );
        }

        return new self(
            $map->lines->knowing(...iterator_to_array($other->durations->tests(), preserve_keys: false)),
            $map->durations->andLonger($other->durations),
            $map->executed,
        );
    }

    /**
     * This map with these lines laid beside its own, built at once: a line
     * both hold keeps the tests of both, and every test it knows keeps its
     * time, with the methods it ran and the packages it loaded.
     */
    public function besides(CoveredLine ...$covered): self
    {
        return new self(
            LineTests::of(...iterator_to_array($this->lines(), preserve_keys: false), ...$covered)
                ->knowing(...iterator_to_array($this->tests(), preserve_keys: false)),
            $this->durations,
            $this->executed,
        );
    }

    /** This map, with these tests covering a line. */
    public function covered(Path $file, Line $line, TestId ...$tests): self
    {
        return new self($this->lines->covered($file, $line, ...$tests), $this->durations, $this->executed);
    }

    /** This map, with how long a test took. */
    public function timed(TestId $test, Seconds $duration): self
    {
        return $this->timedEach(TimedTest::of($test->value(), $duration->seconds()));
    }

    /** This map, with how long each of these tests took; a test timed twice took the later. */
    public function timedEach(TimedTest ...$timed): self
    {
        $tests = array_map(static fn(TimedTest $each): TestId => $each->test(), $timed);

        return new self($this->lines->knowing(...$tests), $this->durations->with(...$timed), $this->executed);
    }

    /** This map, covering only these files: every test it knows and how long each took, and the lines of these. */
    public function onlyFor(Paths $files): self
    {
        return new self($this->lines->onlyFor($files), $this->durations, $this->executed->onlyFor($files));
    }

    /**
     * This map as these of its tests alone ran it: each line with those of them that ran it, and a line none of
     * them ran as missed.
     */
    public function ranBy(TestIds $tests): self
    {
        $lines = [];

        foreach ($this->lines() as $line) {
            $lines[] = CoveredLine::of(
                $line->file(),
                $line->line(),
                ...array_filter([...$line], static fn(string $test): bool => $tests->has(TestId::of($test))),
            );
        }

        return self::of(...$lines);
    }

    /** This map, with what its tests loaded. */
    public function loading(LoadedPackages $loaded): self
    {
        return new self($this->lines, $this->durations, $this->executed->loading($loaded));
    }

    /** This map, with these methods of a file executed, after any it already holds for it. */
    public function executing(Path $file, ExecutedMethod ...$methods): self
    {
        return new self($this->lines, $this->durations, $this->executed->executing($file, ...$methods));
    }

    /** What the map's tests executed beyond its lines: each file's methods and the packages they loaded. */
    public function executed(): Executed
    {
        return $this->executed;
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

    /** The lines the map holds, each with the places of the tests that ran it, as the map's file writes them. */
    public function lineTests(): LineTests
    {
        return $this->lines;
    }

    public function tests(): TestIds
    {
        return $this->lines->tests();
    }

    public function files(): Paths
    {
        return $this->lines->files();
    }

    /** Every test that covers any line of a file from the first to the last, each once. */
    public function testsCovering(Path $file, Line $first, Line $last): TestIds
    {
        return $this->lines->running($file, $first, $last);
    }

    /** Every test that covers any line of a file. */
    public function testsCoveringFile(Path $file): TestIds
    {
        return $this->lines->runningFile($file);
    }

    /** How long each test the map timed took. */
    public function durations(): TestDurations
    {
        return $this->durations;
    }
}
