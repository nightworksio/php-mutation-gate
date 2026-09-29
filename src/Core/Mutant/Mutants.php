<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Mutants, in the order they were reported.
 *
 * @implements IteratorAggregate<int, Mutant>
 */
final readonly class Mutants implements Countable, IteratorAggregate
{
    /** @param list<Mutant> $mutants */
    private function __construct(private array $mutants) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Mutant ...$mutants): self
    {
        return new self(array_values($mutants));
    }

    public function with(Mutant $mutant): self
    {
        return new self([...$this->mutants, $mutant]);
    }

    public function count(): int
    {
        return count($this->mutants);
    }

    /** @return Traversable<int, Mutant> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutants);
    }
}
