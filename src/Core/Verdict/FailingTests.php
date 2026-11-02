<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_map;
use function array_values;

use ArrayIterator;

use function implode;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

use Traversable;

/**
 * Tests that fail without any mutant, as the unmutated control that judges
 * these mutants found them (ADR-0008): a line of its own in the verdict,
 * which cannot be judged, with the mutants it leaves out of every score
 * listed under it, which iterating them gives.
 *
 * @implements IteratorAggregate<int, JudgedMutant>
 */
final readonly class FailingTests implements IteratorAggregate
{
    /** The verdict's line for tests that fail without any mutant, naming them. */
    public const string LINE = 'The tests fail without any mutant: %s';

    /** @param list<JudgedMutant> $mutants */
    private function __construct(private TestIds $tests, private array $mutants)
    {
    }

    public static function of(TestIds $tests, JudgedMutant ...$mutants): self
    {
        return new self($tests, array_values($mutants));
    }

    /** These tests, with one more mutant they leave out. */
    public function with(JudgedMutant $mutant): self
    {
        return new self($this->tests, [...$this->mutants, $mutant]);
    }

    /** The tests that fail, in the order the control recorded them. */
    public function tests(): TestIds
    {
        return $this->tests;
    }

    /** The verdict's line: the tests fail without any mutant, naming each. */
    public function line(): string
    {
        $tests = array_map(static fn(TestId $test): string => $test->value(), [...$this->tests]);

        return sprintf(self::LINE, implode(', ', $tests));
    }

    /** @return Traversable<int, JudgedMutant> the mutants the failing control judges, which every score leaves out */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutants);
    }
}
