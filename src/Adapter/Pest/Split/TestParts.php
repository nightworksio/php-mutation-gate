<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_fill;
use function array_key_exists;
use function array_map;
use function array_sum;
use function count;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Mutant\TestShare;
use NightWorksIO\MutationGate\Core\Test\TestPart;

use function uksort;

/**
 * The test files of a coverage map cut into the parts a run's shards each
 * stream one of (ADR-0117), and the part this shard streams. Each test file,
 * by the file that declares a test's class, goes, longest first by the map's
 * times and files of equal time by path, to the part with the least time so
 * far, of those the fewest files, of those the lowest, so every shard cuts
 * the same map alike. A test belongs to the part of its file. Where a test
 * of the map is declared in no test file the process loaded, no file can be
 * cut by its tests, and the first part holds every test.
 *
 * @internal
 */
final readonly class TestParts
{
    /** @param array<string, int> $parts each test's part, counted from 1, by its id */
    private function __construct(private TestPart $part, private array $parts)
    {
    }

    /** Every test in one part: a run that cuts no test files. */
    public static function whole(): self
    {
        return new self(TestPart::whole(), []);
    }

    /**
     * The map's test files, each with the tests it declares, cut into this
     * part's count of parts by the tests' times, this part the shard's.
     *
     * @param array<string, array{files: list<string>, tests: list<string>}> $units by the file declaring each test
     * @param array<string, float>                                           $times each test's seconds, by its id
     */
    public static function cut(TestPart $part, array $units, array $times): self
    {
        $parts = [];

        foreach (self::assigned($units, $times, $part->parts()) as $file => $at) {
            foreach ($units[$file]['tests'] as $test) {
                $parts[$test] = $at + 1;
            }
        }

        return new self($part, $parts);
    }

    /**
     * Each test file's place among at most this many parts, counted from 0:
     * longest first, files of equal time by path, each to the part with the
     * least time so far, of those the fewest files, of those the lowest.
     *
     * @param  array<string, array{files: list<string>, tests: list<string>}> $units
     * @param  array<string, float>                                           $times
     * @return array<string, int>
     */
    public static function assigned(array $units, array $times, int $parts): array
    {
        $seconds = [];

        foreach ($units as $file => $unit) {
            $seconds[$file] = array_sum(array_map(
                static fn(string $test): float => array_key_exists($test, $times) ? $times[$test] : 0.0,
                $unit['tests'],
            ));
        }

        uksort(
            $seconds,
            static fn(string $one, string $two): int => [$seconds[$two], $one] <=> [$seconds[$one], $two],
        );
        $loads = array_fill(0, min(max(1, $parts), max(1, count($units))), [0.0, 0]);
        $assigned = [];

        foreach ($seconds as $file => $time) {
            $least = self::leastOf($loads);
            $loads[$least] = [$loads[$least][0] + $time, $loads[$least][1] + 1];
            $assigned[$file] = $least;
        }

        return $assigned;
    }

    /** Whether the shard's part holds this test. */
    public function holds(string $test): bool
    {
        return $this->partOf($test) === $this->part->number();
    }

    /**
     * Those of a mutant's covering tests the shard's part holds, in their order.
     *
     * @param  list<string> $covering
     * @return list<string>
     */
    public function within(array $covering): array
    {
        $held = [];

        foreach ($covering as $test) {
            $held = $this->holds($test) ? [...$held, $test] : $held;
        }

        return $held;
    }

    /**
     * Whether the shard owns a mutant whose covering tests these are: its
     * part holds the first of them, or there are none and it is the first.
     *
     * @param list<string> $covering
     */
    public function owns(array $covering): bool
    {
        return $covering === [] ? $this->part->number() === 1 : $this->holds($covering[0]);
    }

    /**
     * How much of a mutant's covering tests the shard's part holds: every
     * one, or some, the shard owning the mutant or not.
     *
     * @param list<string> $covering
     */
    public function shareOf(array $covering): TestShare
    {
        return match (true) {
            $this->within($covering) === $covering => TestShare::All,
            $this->owns($covering) => TestShare::OwnedPart,
            default => TestShare::OtherPart,
        };
    }

    /**
     * The part with the least time so far, of those the fewest files, of
     * those the lowest.
     *
     * @param list<array{float, int}> $loads each part's time and files so far
     */
    private static function leastOf(array $loads): int
    {
        $least = 0;

        foreach ($loads as $at => $load) {
            $least = $load < $loads[$least] ? $at : $least;
        }

        return $least;
    }

    /** The part a test belongs to; the first, for a test of no file cut. */
    private function partOf(string $test): int
    {
        return array_key_exists($test, $this->parts) ? $this->parts[$test] : 1;
    }
}
