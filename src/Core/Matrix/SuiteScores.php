<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use ArrayIterator;

use function count;

use Countable;

use function in_array;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuite;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Traversable;

/**
 * What each suite the project declares alone kills, over a verdict's mutants
 * (ADR-0025, decision 8). A test is in a suite by its file, so a test the
 * runner named no file for is in none. A mutant the score leaves out counts
 * for no suite, and so does one no test of a suite has a known outcome for:
 * a timeout's, a flaky one's, one whose coverage moved, and one no test of
 * the suite ran with, as the tests report leaves them out (ADR-0014,
 * decision 3). A held unit's mutants count only for its group's tests.
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
        $scores = [];

        foreach ($matrix->suites() as $suite) {
            [$covered, $killed] = self::tally($verdict, $suite);
            $scores[] = SuiteScore::of($suite->name(), $covered, $killed, $exact);
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
     * How many of the verdict's mutants the suite's tests cover with a known
     * outcome, and how many of those one of them killed.
     *
     * @return array{int, int}
     */
    private static function tally(Verdict $verdict, DeclaredSuite $suite): array
    {
        $covered = 0;
        $killed = 0;

        foreach ($verdict->trees()->mutants() as $judged) {
            $outcome = self::outcomeFor($verdict->matrix(), $judged, $suite);
            $covered += $outcome === Outcome::Killed || $outcome === Outcome::Passed ? 1 : 0;
            $killed += $outcome === Outcome::Killed ? 1 : 0;
        }

        return [$covered, $killed];
    }

    /**
     * What the suite's tests did with the mutant, taken together: killed
     * where one of them killed it; unknown where one of them has no known
     * outcome; passed where one of them passed it, or where each was stopped
     * behind another suite's first kill, which a lower bound counts as not
     * killed; not run where no test of the suite ran with it, as for a
     * mutant a static analyser killed, or the score leaves it out.
     */
    private static function outcomeFor(
        KillMatrix $matrix,
        JudgedMutant|JudgedKill $judged,
        DeclaredSuite $suite,
    ): Outcome {
        if ($judged->judgement()->scoring(Uncovered::Count) === Scoring::LeftOut) {
            return Outcome::NotRun;
        }

        $outcomes = [];

        foreach ($matrix->coveredBy($judged) as $test) {
            if ($matrix->judges($judged, $test) && self::holds($matrix, $suite, $test)) {
                $outcomes[] = $matrix->outcome($judged, $test);
            }
        }

        return match (true) {
            in_array(Outcome::Killed, $outcomes, strict: true) => Outcome::Killed,
            in_array(Outcome::Unknown, $outcomes, strict: true) => Outcome::Unknown,
            in_array(Outcome::Passed, $outcomes, strict: true),
            $outcomes !== [] && count($judged->mutant()->killers()) > 0 => Outcome::Passed,
            default => Outcome::NotRun,
        };
    }

    /** Whether a test is in the suite, by the file the runner named it in. */
    private static function holds(KillMatrix $matrix, DeclaredSuite $suite, TestId $test): bool
    {
        $named = $matrix->names()->testOf($test);

        return $named instanceof TestName && $suite->holds($named->file());
    }
}
