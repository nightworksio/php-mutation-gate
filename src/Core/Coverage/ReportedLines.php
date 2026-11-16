<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_values;

use Closure;

use function ksort;

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function rtrim;
use function sort;
use function sprintf;

/**
 * The executable lines of php-code-coverage's PHP report ({@see PhpReport}),
 * each with the tests that ran it by their index in the report's list of
 * tests, in the order that list names them, as `array_intersect_key()` of
 * the list and the line's hits keeps them; none for a line no test ran. A
 * line's hits are read against the list once, rather than the whole list
 * against each line.
 *
 * @internal
 */
final readonly class ReportedLines
{
    /**
     * @param array<int, string>                  $ids   each test's id, by its index, in the report's order
     * @param array<string, array<int, list<int>>> $lines the indices of the tests on each executable line, by the
     *                                                    file's path on disk and the line
     */
    private function __construct(private array $ids, private array $lines)
    {
    }

    /**
     * The lines of a report under a base path: its tests by their index, as
     * `testIds()` lists them, and each line's hits by test index, null for a
     * line that is not executable, by each file's path from the base.
     *
     * @param array<int, string>                               $ids
     * @param array<array-key, array<int, array<int, int>|null>> $coverage
     */
    public static function of(string $basePath, array $ids, array $coverage): self
    {
        $indices = array_keys($ids);
        $rank = self::isAscending($indices) ? [] : array_flip($indices);
        $lines = [];

        foreach ($coverage as $file => $covered) {
            $path = sprintf('%s/%s', rtrim($basePath, '/'), $file);

            foreach ($covered as $line => $hits) {
                if ($hits !== null) {
                    $lines[$path][$line] = self::ordered(array_keys(array_intersect_key($hits, $ids)), $rank);
                }
            }
        }

        return new self($ids, $lines);
    }

    /**
     * The map of these lines, each file by the path the closure makes of its
     * path on disk: every test placed where a line first names it, file by
     * file and line by line, as `CoverageMap::of()` places the tests of
     * covered lines.
     *
     * @param Closure(string): Path $relative
     */
    public function map(Closure $relative, TimedTest ...$timed): CoverageMap
    {
        $places = $this->places();
        $placed = [];

        foreach ($this->lines as $file => $lines) {
            $path = $relative($file);

            foreach ($lines as $line => $indices) {
                $placed[] = Tally::placing($path, $line, $indices, $places);
            }
        }

        $tests = [];

        foreach (array_keys($places) as $index) {
            $tests[] = TestId::of($this->ids[$index]);
        }

        return CoverageMap::placed(LineTests::placed(TestIds::of(...$tests), ...$placed), ...$timed);
    }

    /** Each test that ran a line of a file from the first to the last, for each line it ran, line by line. */
    public function covering(DiskPath $file, Line $first, Line $last): TestIds
    {
        $lines = array_key_exists($file->value(), $this->lines) ? $this->lines[$file->value()] : [];
        $tests = [];

        foreach ($lines as $line => $indices) {
            $covered = $line >= $first->number() && $line <= $last->number();

            foreach ($covered ? $indices : [] as $index) {
                $tests[] = TestId::of($this->ids[$index]);
            }
        }

        return TestIds::of(...$tests);
    }

    /**
     * Each test's place among the tests, by its index: where a line first
     * names it, file by file and line by line.
     *
     * @return array<int, int<0, max>>
     */
    private function places(): array
    {
        $first = [];

        foreach ($this->lines as $lines) {
            foreach ($lines as $indices) {
                $first += array_flip($indices);
            }
        }

        return array_flip(array_keys($first));
    }

    /** @param list<int> $indices */
    private static function isAscending(array $indices): bool
    {
        $sorted = $indices;
        sort($sorted);

        return $sorted === $indices;
    }

    /**
     * Some indices in the order the list names them: by their own order,
     * where the rank is empty and the list names its indices from the lowest
     * up, or else by each index's place in the list.
     *
     * @param  list<int>       $indices
     * @param  array<int, int> $rank
     * @return list<int>
     */
    private static function ordered(array $indices, array $rank): array
    {
        if ($rank === []) {
            sort($indices);

            return $indices;
        }

        $byRank = [];

        foreach ($indices as $index) {
            $byRank[$rank[$index]] = $index;
        }

        ksort($byRank);

        return array_values($byRank);
    }
}
