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
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
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

        foreach (self::tallies($verdict) as [$test, $judged, $killedFirst, $ranBehind]) {
            $whole = $names->testOf($test);
            $row = TestStanding::of($names->nameOf($test), $judged, $killedFirst, $ranBehind);
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
     * @return array<string, array{TestId, int, bool, bool}>
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

                $tally = array_key_exists($test->value(), $tallies)
                    ? $tallies[$test->value()]
                    : [$test, 0, false, false];
                $outcome = $matrix->outcome($judged, $test);
                $tallies[$test->value()] = self::tallied($tally, $outcome, self::wasKilled($judged));
            }
        }

        return $tallies;
    }

    /**
     * A test's tally with one more cell: how many mutants it judged with a known result, and whether it killed
     * any first or ran behind another test's first kill in any.
     *
     * @param  array{TestId, int, bool, bool} $tally
     * @return array{TestId, int, bool, bool}
     */
    private static function tallied(array $tally, Outcome $outcome, bool $killed): array
    {
        [$test, $judged, $killedFirst, $ranBehind] = $tally;

        return match (true) {
            $outcome === Outcome::Killed => [$test, $judged + 1, true, $ranBehind],
            $outcome === Outcome::Passed => [$test, $judged + 1, $killedFirst, $ranBehind],
            $outcome === Outcome::NotRun && $killed => [$test, $judged + 1, $killedFirst, true],
            default => $tally,
        };
    }

    /**
     * Whether a test killed the mutant first, so a test the run stopped
     * before ran behind that kill. A mutant killed with no killer known, as
     * by a static analyser before any test ran, had no first kill to run
     * behind.
     */
    private static function wasKilled(JudgedMutant|JudgedKill $judged): bool
    {
        return $judged->judgement()->scoring(Uncovered::Count) === Scoring::Killed
            && count($judged->mutant()->killers()) > 0;
    }
}
