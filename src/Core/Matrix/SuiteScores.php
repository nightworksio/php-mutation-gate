<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function array_fill;

use ArrayIterator;

use function count;

use Countable;
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
        $covered = array_fill(0, count($suites), 0);
        $killed = $covered;
        $placed = [];

        foreach ($verdict->trees()->mutants() as $judged) {
            foreach (self::outcomesFor($matrix, $judged, $suites, $placed) as $at => $outcome) {
                $covered[$at] += $outcome->ran() ? 1 : 0;
                $killed[$at] += $outcome === Outcome::Killed ? 1 : 0;
            }
        }

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
     * What each suite's tests did with the mutant, taken together, by the
     * suite's place among them: killed where one of them killed it; unknown
     * where one of them has no known outcome; passed where one of them
     * passed it, or where each was stopped behind another suite's first
     * kill, which a lower bound counts as not killed; and no entry where no
     * test of the suite ran with it, as for a mutant a static analyser
     * killed, or the score leaves it out. The mutant's covering tests are
     * read once for every suite.
     *
     * @param  list<DeclaredSuite>     $suites
     * @param  array<string, list<int>> $placed the places of the suites each test read so far is in, by its id
     * @return array<int, Outcome>
     */
    private static function outcomesFor(
        KillMatrix $matrix,
        JudgedMutant|JudgedKill $judged,
        array $suites,
        array &$placed,
    ): array {
        if ($judged->judgement()->scoring(Uncovered::Count) === Scoring::LeftOut) {
            return [];
        }

        $seen = [];

        foreach ($matrix->coveredBy($judged) as $test) {
            if (! $matrix->judges($judged, $test)) {
                continue;
            }

            $placed[$test->value()] ??= self::placesOf($matrix, $suites, $test);
            $outcome = $matrix->outcome($judged, $test);

            foreach ($placed[$test->value()] as $at) {
                $seen[$at][$outcome->value] = true;
            }
        }

        $stopped = count($judged->mutant()->killers()) > 0;
        $outcomes = [];

        foreach ($seen as $at => $outcome) {
            $outcomes[$at] = match (true) {
                isset($outcome[Outcome::Killed->value]) => Outcome::Killed,
                isset($outcome[Outcome::Unknown->value]) => Outcome::Unknown,
                isset($outcome[Outcome::Passed->value]), $stopped => Outcome::Passed,
                default => Outcome::NotRun,
            };
        }

        return $outcomes;
    }

    /**
     * The places of the suites a test is in, by the file the runner named it
     * in; none for a test the runner named no file for.
     *
     * @param  list<DeclaredSuite> $suites
     * @return list<int>
     */
    private static function placesOf(KillMatrix $matrix, array $suites, TestId $test): array
    {
        $named = $matrix->names()->testOf($test);
        $places = [];

        foreach ($suites as $at => $suite) {
            if ($named instanceof TestName && $suite->holds($named->file())) {
                $places[] = $at;
            }
        }

        return $places;
    }
}
