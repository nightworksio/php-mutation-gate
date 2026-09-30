<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function array_key_exists;
use function array_values;

use ArrayIterator;
use IteratorAggregate;

use function max;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Traversable;

/**
 * Every whole test a verdict's mutants name, in the order they first name
 * it, with what its rows killed and how long they ran.
 *
 * @implements IteratorAggregate<int, KillingTest>
 */
final readonly class KillingTests implements IteratorAggregate
{
    /** @param list<KillingTest> $tests */
    private function __construct(private array $tests)
    {
    }

    public static function of(Verdict $verdict): self
    {
        $matrix = $verdict->matrix();
        $wholes = [];

        foreach (self::killsByRow($verdict) as [$test, $kills]) {
            $whole = $matrix->names()->testOf($test);
            $earlier = array_key_exists($whole->value(), $wholes) ? $wholes[$whole->value()] : KillingTest::of($whole);
            $wholes[$whole->value()] = $earlier->withRow($kills, $matrix->secondsOf($test));
        }

        return new self(array_values($wholes));
    }

    /** The time of the slowest timed test, which an untimed one is taken to run for; none where none was timed. */
    public function slowest(): Seconds
    {
        $slowest = 0.0;

        foreach ($this->tests as $test) {
            $slowest = max($slowest, $test->secondsOr(Seconds::of(0.0))->seconds());
        }

        return Seconds::of($slowest);
    }

    /** @return Traversable<int, KillingTest> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->tests);
    }

    /**
     * Each test the mutants name, with the mutants it killed.
     *
     * @return array<string, array{TestId, MutantIds}>
     */
    private static function killsByRow(Verdict $verdict): array
    {
        $matrix = $verdict->matrix();
        $rows = [];

        foreach ($verdict->mutants() as $judged) {
            foreach ($matrix->coveredBy($judged) as $test) {
                $kills = array_key_exists($test->value(), $rows) ? $rows[$test->value()][1] : MutantIds::none();
                $killed = $matrix->outcome($judged, $test) === Outcome::Killed;
                $rows[$test->value()] = [$test, $killed ? $kills->and(MutantIds::of($judged->mutant()->id())) : $kills];
            }
        }

        return $rows;
    }
}
