<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function count;
use function explode;
use function min;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usort;

/**
 * The judging tests of a heavy mutant split into parts that each fit under
 * `timeouts.most` (ADR-0008): their files nearest the mutant's file first,
 * by how many leading directories their paths share with its, then the
 * fastest, by the map's own time; a file's tests together in one part where
 * they fit in one, and one test at a time where they do not. A test the
 * runner places in no file comes last.
 */
final readonly class MutantParts
{
    /** What separates the directories of a path. */
    private const string SEPARATOR = '/';

    private function __construct(private CoverageMap $map, private LimitBounds $bounds)
    {
    }

    public static function under(CoverageMap $map, LimitBounds $bounds): self
    {
        return new self($map, $bounds);
    }

    /** Whether these tests are too many to run in one run under the most: their standard limit is above it. */
    public function overflow(TestIds $tests): bool
    {
        return ! $this->fits($tests);
    }

    /**
     * These tests of a mutant of this file, in parts that each fit, in the
     * order they run.
     *
     * @return list<RunTests>
     */
    public function of(Path $file, TestIds $tests, TestPlaces $places): array
    {
        $parts = [];
        $part = TestIds::none();

        foreach ($this->pieces($file, $tests, $places) as $piece) {
            $together = $part->and($piece);
            $fits = $this->fits($together);
            $parts = $fits || count($part) === 0 ? $parts : [...$parts, RunTests::of($part)];
            $part = $fits ? $together : $piece;
        }

        return count($part) === 0 ? $parts : [...$parts, RunTests::of($part)];
    }

    /**
     * These tests in the order they run, a file's together where they fit
     * in one part, and one by one where they do not.
     *
     * @return list<TestIds>
     */
    private function pieces(Path $file, TestIds $tests, TestPlaces $places): array
    {
        $pieces = [];

        foreach ($this->groups($file, $tests, $places) as $group) {
            $pieces = [...$pieces, ...$this->fits($group) ? [$group] : $this->single($group)];
        }

        return $pieces;
    }

    /**
     * These tests by the file each is in, nearest the mutant's file first,
     * then the fastest, those in no file last, one by one.
     *
     * @return list<TestIds>
     */
    private function groups(Path $file, TestIds $tests, TestPlaces $places): array
    {
        $placed = $places->holding($tests);
        usort($placed, fn(array $one, array $other): int => $this->rank($file, $one) <=> $this->rank($file, $other));
        $groups = [];
        $seen = TestIds::none();

        foreach ($placed as [, $held]) {
            $groups[] = $held;
            $seen = $seen->and($held);
        }

        return [...$groups, ...$this->single($tests->without($seen))];
    }

    /**
     * These tests one by one, the fastest first.
     *
     * @return list<TestIds>
     */
    private function single(TestIds $tests): array
    {
        $each = [];

        foreach ($tests as $test) {
            $each[] = TestIds::of($test);
        }

        usort($each, fn(TestIds $one, TestIds $other): int => $this->seconds($one) <=> $this->seconds($other));

        return $each;
    }

    /**
     * Where a test file and the tests it holds come among those of a mutant
     * of a file: the more directories it shares with it, the sooner, then
     * the faster, then by its path.
     *
     * @param  array{Path, TestIds}     $held
     * @return array{int, float, string}
     */
    private function rank(Path $file, array $held): array
    {
        return [-$this->shared($file, $held[0]), $this->seconds($held[1]), $held[0]->value()];
    }

    /** How many leading directories two paths share. */
    private function shared(Path $one, Path $other): int
    {
        $first = explode(self::SEPARATOR, $one->value());
        $second = explode(self::SEPARATOR, $other->value());
        $shared = 0;

        for ($at = 0; $at < min(count($first), count($second)) - 1 && $first[$at] === $second[$at]; $at++) {
            $shared++;
        }

        return $shared;
    }

    /** Whether the standard limit of these tests' own time is at most the most. */
    private function fits(TestIds $tests): bool
    {
        $unbounded = $this->bounds->upToInstead(Seconds::of(PHP_FLOAT_MAX));

        return MutantLimit::standard()->of(OwnTime::of($this->map, $tests), $unbounded)->seconds()
            <= $this->bounds->most()->seconds();
    }

    /** The map's own time of these tests, none where it did not time them. */
    private function seconds(TestIds $tests): float
    {
        $time = OwnTime::of($this->map, $tests);

        return $time instanceof Seconds ? $time->seconds() : 0.0;
    }
}
