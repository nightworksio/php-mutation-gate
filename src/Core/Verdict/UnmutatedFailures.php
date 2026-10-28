<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;

use function implode;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use Traversable;

/**
 * Every set of tests that fails without any mutant, with the mutants each
 * leaves out of every score, in the order the mutants were judged. Any one of
 * them makes the verdict *cannot judge* (ADR-0008).
 *
 * @implements IteratorAggregate<int, FailingTests>
 */
final readonly class UnmutatedFailures implements Countable, IteratorAggregate
{
    /** What joins the tests of a set into its key. */
    private const string BETWEEN = "\n";

    /** @param list<FailingTests> $failing */
    private function __construct(private array $failing)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** The sets of tests that fail without these mutants, each once, each with every mutant it leaves out. */
    public static function in(JudgedMutants $mutants): self
    {
        $leftOut = [];

        foreach ($mutants as $judged) {
            $leftOut += $judged instanceof JudgedMutant && self::leftOut($judged->mutant())
                ? [$judged->mutant()->id()->key() => $judged]
                : [];
        }

        $failing = [];

        foreach ($leftOut as $judged) {
            $tests = $judged->mutant()->killers();
            $key = self::keyOf($tests);
            $failing[$key] = array_key_exists($key, $failing)
                ? $failing[$key]->with($judged)
                : FailingTests::of($tests, $judged);
        }

        return new self(array_values($failing));
    }

    public function count(): int
    {
        return count($this->failing);
    }

    /** @return Traversable<int, FailingTests> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->failing);
    }

    /** Whether a mutant's tests fail without it, which leaves it out of every score. */
    private static function leftOut(Mutant $mutant): bool
    {
        return $mutant->status() === MutantStatus::FailsUnmutated;
    }

    /** What tells one set of tests from another: their ids, in their order. */
    private static function keyOf(TestIds $tests): string
    {
        $ids = [];

        foreach ($tests as $test) {
            $ids[] = $test->value();
        }

        return implode(self::BETWEEN, $ids);
    }
}
