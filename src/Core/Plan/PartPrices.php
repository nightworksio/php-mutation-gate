<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_fill;
use function array_key_exists;
use function array_sum;
use function array_values;
use function max;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Test\TestFileParts;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The seconds of each unit's covering tests in each part of the test files
 * a run cuts (ADR-0117), the test files placed by the coverage map's times
 * by the rule each shard places them by, each by the class that declares
 * its tests: a unit's mutants are judged in a part by the part's tests
 * that cover them, so its cost in a part is its share of those seconds
 * there, nothing where none of them is, and its cost over the parts alike
 * where none is timed.
 *
 * @internal
 */
final readonly class PartPrices
{
    /**
     * @param int                        $parts   how many parts the test files are cut into
     * @param array<string, list<float>> $seconds each unit's covering tests' seconds in each part, counted from 0, by
     *                                            its path
     */
    private function __construct(private int $parts, private array $seconds)
    {
    }

    /** Every unit's cost shared alike among the parts. */
    public static function alike(): self
    {
        return new self(0, []);
    }

    /** The covering tests' seconds of these units in each of this many parts, as this map times and places them. */
    public static function of(CoverageMap $map, int $parts, Weighed ...$units): self
    {
        $timed = self::timed($map);
        $files = [];

        foreach ($timed as [$file, $seconds]) {
            $files[$file] = (array_key_exists($file, $files) ? $files[$file] : 0.0) + $seconds;
        }

        $count = max(1, $parts);
        $placed = TestFileParts::placed($files, $count);
        $prices = [];

        foreach ($units as $unit) {
            $path = $unit->unit()->path();
            $prices[$path->value()] = self::spread($map->testsCoveringFile($path), $timed, $placed, $count);
        }

        return new self($count, $prices);
    }

    /**
     * The unit weighed in this part: at its share there of its covering
     * tests' seconds, or at an alike share where none of them is timed or
     * the part is of another cut.
     */
    public function weighed(Weighed $unit, TestPart $part): Weighed
    {
        $path = $unit->unit()->path()->value();
        $each = $part->parts() === $this->parts && array_key_exists($path, $this->seconds) ? $this->seconds[$path] : [];
        $whole = array_sum($each);

        return $whole > 0.0 ? $unit->sharedAs($each[$part->number() - 1], $whole) : $unit->sharedAmong($part);
    }

    /**
     * The seconds of these tests in each of this many parts, counted from 0:
     * each timed test's in the part its class's test file is placed in.
     *
     * @param  array<string, array{string, float}> $timed  each test the map times, with its class and seconds, by
     *                                                     its id
     * @param  array<string, int>                  $placed each test file's part, counted from 0, by its class
     * @return list<float>
     */
    private static function spread(TestIds $tests, array $timed, array $placed, int $parts): array
    {
        $each = array_fill(0, $parts, 0.0);

        foreach ($tests as $test) {
            if (array_key_exists($test->value(), $timed)) {
                [$file, $seconds] = $timed[$test->value()];
                $each[$placed[$file]] += $seconds;
            }
        }

        return array_values($each);
    }

    /**
     * Each test the map times, with the class that declares it and its seconds.
     *
     * @return array<string, array{string, float}>
     */
    private static function timed(CoverageMap $map): array
    {
        $durations = $map->durations();
        $timed = [];

        foreach ($durations->tests() as $test) {
            $seconds = $durations->of($test);
            $timed[$test->value()] = [
                TestMethod::classOf($test),
                $seconds instanceof Seconds ? $seconds->seconds() : 0.0,
            ];
        }

        return $timed;
    }
}
