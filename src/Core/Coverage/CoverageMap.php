<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_key_exists;
use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * Which tests ran which line of which file, and how long each test took. A
 * test the map knows need not cover anything.
 */
final readonly class CoverageMap
{
    /**
     * @param array<string, array<int, TestIds>> $lines     each covered line of each file, by path and line number
     * @param TestIds                            $tests     every test the map knows
     * @param array<string, Seconds>             $durations each timed test's duration, by id
     */
    private function __construct(private array $lines, private TestIds $tests, private array $durations) {}

    public static function empty(): self
    {
        return new self([], TestIds::none(), []);
    }

    /** This map, with a test covering a line. */
    public function covered(Path $file, Line $line, TestId $test): self
    {
        $lines = $this->lines;
        $lines[$file->value()][$line->number()] = $this->testsCovering($file, $line)->with($test);

        return new self($lines, $this->tests->with($test), $this->durations);
    }

    /** This map, with how long a test took. */
    public function timed(TestId $test, Seconds $duration): self
    {
        $durations = $this->durations;
        $durations[$test->value()] = $duration;

        return new self($this->lines, $this->tests->with($test), $durations);
    }

    public function tests(): TestIds
    {
        return $this->tests;
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

        return array_key_exists($line->number(), $lines) ? $lines[$line->number()] : TestIds::none();
    }

    /** Every test that covers any line of a file. */
    public function testsCoveringFile(Path $file): TestIds
    {
        $tests = TestIds::none();

        foreach ($this->linesOf($file) as $covering) {
            $tests = TestIds::of(...$tests, ...$covering);
        }

        return $tests;
    }

    public function durationOf(TestId $test): Seconds|Unmeasured
    {
        return array_key_exists($test->value(), $this->durations) ? $this->durations[$test->value()] : Unmeasured::duration();
    }

    /** @return array<int, TestIds> */
    private function linesOf(Path $file): array
    {
        return array_key_exists($file->value(), $this->lines) ? $this->lines[$file->value()] : [];
    }
}
