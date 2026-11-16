<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_map;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function range;

/**
 * A file a save changed, as a quick round of `watch` covers it (ADR-0146):
 * every line of it by every test the last round's map shows running any line
 * of it, and every test of the test files the static graph says reach it, so
 * a line the save moved or added is never left without a test that may run
 * it. A split stream forks a mutant only where a test executes its site, so
 * a test that runs none of these lines costs time and judges nothing.
 *
 * @internal
 */
final readonly class QuickCovered
{
    private function __construct(private Path $file, private int $lines, private TestIds $tests)
    {
    }

    /** A file of this many lines, covered by these tests. */
    public static function of(Path $file, int $lines, TestIds $tests): self
    {
        return new self($file, $lines, $tests);
    }

    /** The map with each of these files covered on every line by its tests; a file no test covers stays as it is. */
    public static function over(CoverageMap $map, self ...$files): CoverageMap
    {
        $covered = [];

        foreach ($files as $file) {
            $tests = array_map(
                static fn(TestId $test): string => $test->value(),
                iterator_to_array($file->tests, preserve_keys: false),
            );

            foreach ($tests === [] || $file->lines < 1 ? [] : range(1, $file->lines) as $line) {
                $covered[] = CoveredLine::of($file->file, $line, ...$tests);
            }
        }

        return $covered === [] ? $map : $map->besides(...$covered);
    }
}
