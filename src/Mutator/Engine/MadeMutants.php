<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The mutants the engine made of one file, in the order of their lines.
 *
 * @internal the engine's own
 *
 * @implements IteratorAggregate<int, MadeMutant>
 */
final readonly class MadeMutants implements Countable, IteratorAggregate
{
    /** @param list<MadeMutant> $mutants */
    private function __construct(private array $mutants)
    {
    }

    public static function of(MadeMutant ...$mutants): self
    {
        return new self(array_values($mutants));
    }

    public function count(): int
    {
        return count($this->mutants);
    }

    /** @return Traversable<int, MadeMutant> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutants);
    }
}
