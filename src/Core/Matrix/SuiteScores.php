<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function array_fill;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuite;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Traversable;

/**
 * What each suite the project declares alone kills, over a verdict's mutants
 * (ADR-0025). A test is in a suite by its file, so a test the
 * runner named no file for is in none. A mutant the score leaves out counts
 * for no suite, and so does one no test of a suite has a known outcome for:
 * a timeout's, a flaky one's, one whose coverage moved, and one no test of
 * the suite ran with, as the tests report leaves them out (ADR-0014). A
 * held unit's mutants count only for its group's tests.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, SuiteScore>
 */
final readonly class SuiteScores implements Countable, IteratorAggregate
{
    /** @param list<SuiteScore> $scores */
    private function __construct(private array $scores, private bool $placed)
    {
    }

    public static function of(Verdict $verdict): self
    {
        $matrix = $verdict->matrix();
        $exact = $matrix->kind() === MatrixKind::Full;
        $suites = [...$matrix->suites()];
        [$covered, $killed] = self::tally($verdict, $suites);
        $scores = [];

        foreach ($suites as $at => $suite) {
            $scores[] = SuiteScore::of($suite->name(), $covered[$at], $killed[$at], $exact);
        }

        return new self($scores, count($matrix->names()) > 0);
    }

    /**
     * Whether the runner named the tests, so each could be put in a suite by
     * its file; a run whose tests it named none of scores no suite.
     */
    public function arePlaced(): bool
    {
        return $this->placed;
    }

    public function count(): int
    {
        return count($this->scores);
    }

    /** @return Traversable<int, SuiteScore> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->scores);
    }

    /**
     * How many of the verdict's mutants each suite's tests cover with a known
     * outcome, and how many of those one of them killed, by the suite's place.
     * Each mutant's covering tests are read once for every suite.
     *
     * @param  list<DeclaredSuite>                   $suites
     * @return array{array<int, int>, array<int, int>}
     */
    private static function tally(Verdict $verdict, array $suites): array
    {
        $matrix = $verdict->matrix();
        $placed = self::placed($matrix, $suites);
        $covered = array_fill(0, count($suites), 0);
        $killed = $covered;

        foreach ($verdict->trees()->mutants() as $judged) {
            foreach (self::outcomesFor($matrix, $judged, $placed) as $at => $outcome) {
                $covered[$at] += (int) $outcome->ran();
                $killed[$at] += (int) ($outcome === Outcome::Killed);
            }
        }

        return [$covered, $killed];
    }

    /**
     * What each suite's tests did with the mutant, taken together, by the
     * suite's place: killed where one of them killed it; unknown where one of
     * them has no known outcome; passed where one of them passed it, or where
     * each was stopped behind another suite's first kill, which a lower bound
     * counts as not killed; and no entry where no test of the suite ran with
     * it, as for a mutant a static analyser killed, or the score leaves it out.
     *
     * @param  array<string, list<int>> $placed the places of the suites each named test is in, by its id
     * @return array<int, Outcome>
     */
    private static function outcomesFor(KillMatrix $matrix, JudgedMutant|JudgedKill $judged, array $placed): array
    {
        if ($judged->judgement()->scoring(Uncovered::Count) === Scoring::LeftOut) {
            return [];
        }

        $stopped = count($judged->mutant()->killers()) > 0;

        return array_map(
            static fn(array $seen): Outcome => self::together($seen, $stopped),
            self::seenBy($matrix, $judged, $placed),
        );
    }

    /**
     * The outcomes the mutant's judging tests had, by each suite's place,
     * keyed by the outcome.
     *
     * @param  array<string, list<int>>                $placed
     * @return array<int, array<string, Outcome>>
     */
    private static function seenBy(KillMatrix $matrix, JudgedMutant|JudgedKill $judged, array $placed): array
    {
        $seen = [];

        foreach ($matrix->coveredBy($judged) as $test) {
            if (! $matrix->judges($judged, $test) || ! array_key_exists($test->value(), $placed)) {
                continue;
            }

            $outcome = $matrix->outcome($judged, $test);

            foreach ($placed[$test->value()] as $at) {
                $seen[$at][$outcome->value] = $outcome;
            }
        }

        return $seen;
    }

    /**
     * One suite's tests' outcomes with the mutant, taken together.
     *
     * @param array<string, Outcome> $seen
     */
    private static function together(array $seen, bool $stopped): Outcome
    {
        return match (true) {
            array_key_exists(Outcome::Killed->value, $seen) => Outcome::Killed,
            array_key_exists(Outcome::Unknown->value, $seen) => Outcome::Unknown,
            array_key_exists(Outcome::Passed->value, $seen), $stopped => Outcome::Passed,
            default => Outcome::NotRun,
        };
    }

    /**
     * The places of the suites each named test is in, by the file the runner
     * named it in, by the test's id; none for a test the runner named no file
     * for, and no entry for a test it named not at all.
     *
     * @param  list<DeclaredSuite>      $suites
     * @return array<string, list<int>>
     */
    private static function placed(KillMatrix $matrix, array $suites): array
    {
        $placed = [];

        $names = $matrix->names();

        foreach ($names as $test => $row) {
            $named = $names->testOf($test);
            $holding = static fn(DeclaredSuite $suite): bool => $named instanceof TestName
                && $suite->holds($named->file());
            $placed[$test->value()] = array_keys(array_filter($suites, $holding));
        }

        return $placed;
    }
}
