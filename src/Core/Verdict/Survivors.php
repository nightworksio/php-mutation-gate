<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The mutants a score counts as not killed, those on changed lines first.
 * Each is a mutant a run reported in full, with its diff and family: a kill a
 * ledger proved is never one.
 *
 * @implements IteratorAggregate<int, JudgedMutant>
 */
final readonly class Survivors implements Countable, IteratorAggregate
{
    /** @param list<JudgedMutant> $mutants */
    private function __construct(private array $mutants)
    {
    }

    public static function of(JudgedMutant ...$mutants): self
    {
        return new self(array_values($mutants));
    }

    public function count(): int
    {
        return count($this->mutants);
    }

    /** @return Traversable<int, JudgedMutant> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutants);
    }
}
