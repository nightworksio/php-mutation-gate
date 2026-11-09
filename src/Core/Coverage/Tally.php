<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * Covered lines, read into what LineTests holds: each test's place in the
 * list of tests, in the order they are first read, and each line's places in
 * the order its tests ran it.
 *
 * @internal
 */
final readonly class Tally
{
    /** The lines, each test placed where it is first read; a line read twice holds the tests of both. */
    public static function of(CoveredLine ...$covered): LineTests
    {
        $tests = [];
        $places = [];
        $lines = [];

        foreach ($covered as $line) {
            $by = [];

            foreach ($line as $id) {
                if (! array_key_exists($id, $places)) {
                    $places[$id] = count($tests);
                    $tests[] = TestId::of($id);
                }

                $by[] = $places[$id];
            }

            $lines[] = PlacedLine::of($line->file(), $line->line(), ...$by);
        }

        return LineTests::placed(TestIds::of(...$tests), ...$lines);
    }

    /**
     * A line whose tests are found by their keys: each key's place in a list
     * of tests, in the order of the keys.
     *
     * @param list<int>               $keys
     * @param array<int, int<0, max>> $places each key's place
     */
    public static function placing(Path $file, int $line, array $keys, array $places): PlacedLine
    {
        $placed = [];

        foreach ($keys as $key) {
            $placed[] = $places[$key];
        }

        return PlacedLine::of($file, $line, ...$placed);
    }
}
