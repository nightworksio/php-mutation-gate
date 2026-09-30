<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Traversable;

/**
 * Every test a verdict's mutants name, whole, with what the mutants each
 * judged with a known result say of it: a mutant it killed makes it useful;
 * a killed mutant it ran behind another's first kill in, a suspicion; and a
 * mutant that passed it, nothing caught. A timeout's, a flaky mutant's, and
 * one never run are left out, and a held unit's mutants count only for
 * its group's tests (ADR-0014, decisions 2 and 3).
 *
 * @implements IteratorAggregate<int, WholeTest>
 */
final readonly class TestStandings implements Countable, IteratorAggregate
{
    /** @param list<WholeTest> $tests */
    private function __construct(private array $tests)
    {
    }

    public static function of(Verdict $verdict): self
    {
        $names = $verdict->matrix()->names();
        $wholes = [];

        foreach (self::tallies($verdict) as [$test, $judged, $kills, $beaten]) {
            $whole = $names->testOf($test);
            $row = TestStanding::of($names->nameOf($test), $judged, $kills, $beaten);
            $wholes[$whole->value()] = array_key_exists($whole->value(), $wholes)
                ? $wholes[$whole->value()]->with($row)
                : WholeTest::of($whole, $row);
        }

        return new self(array_values($wholes));
    }

    /** The tests that stand so, in their order. */
    public function thatStand(Standing $standing): self
    {
        $those = [];

        foreach ($this->tests as $test) {
            if ($test->standing() === $standing) {
                $those[] = $test;
            }
        }

        return new self($those);
    }

    public function count(): int
    {
        return count($this->tests);
    }

    /** @return Traversable<int, WholeTest> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->tests);
    }

    /**
     * Each test's tally over every mutant it covers, in the order the mutants first name it.
     *
     * @return array<string, array{TestId, int, int, int}>
     */
    private static function tallies(Verdict $verdict): array
    {
        $matrix = $verdict->matrix();
        $tallies = [];

        foreach ($verdict->trees()->mutants() as $judged) {
            foreach ($matrix->coveredBy($judged) as $test) {
                if (! $matrix->judges($judged, $test)) {
                    continue;
                }

                $tally = array_key_exists($test->value(), $tallies) ? $tallies[$test->value()] : [$test, 0, 0, 0];
                $outcome = $matrix->outcome($judged, $test);
                $tallies[$test->value()] = self::tallied($tally, $outcome, self::wasKilled($judged));
            }
        }

        return $tallies;
    }

    /**
     * A test's tally with one more cell: what it judged with a known result, killed first, and ran behind.
     *
     * @param  array{TestId, int, int, int} $tally
     * @return array{TestId, int, int, int}
     */
    private static function tallied(array $tally, Outcome $outcome, bool $killed): array
    {
        [$test, $judged, $kills, $beaten] = $tally;

        return match (true) {
            $outcome === Outcome::Killed => [$test, $judged + 1, $kills + 1, $beaten],
            $outcome === Outcome::Passed => [$test, $judged + 1, $kills, $beaten],
            $outcome === Outcome::NotRun && $killed => [$test, $judged + 1, $kills, $beaten + 1],
            default => $tally,
        };
    }

    /** Whether the gate counts the mutant as killed, so a test the run stopped before ran behind its first kill. */
    private static function wasKilled(JudgedMutant $judged): bool
    {
        return $judged->judgement()->scoring(Uncovered::Count) === Scoring::Killed;
    }
}
