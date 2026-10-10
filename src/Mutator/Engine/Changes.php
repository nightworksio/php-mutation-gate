<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_values;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Mutators' changes to one piece of code, mutator by mutator, each one's in the
 * order the nodes stand.
 *
 * @internal the engine's own
 *
 * @implements IteratorAggregate<int, Change>
 */
final readonly class Changes implements IteratorAggregate
{
    /** @param list<Change> $changes */
    private function __construct(private array $changes)
    {
    }

    public static function of(Change ...$changes): self
    {
        return new self(array_values($changes));
    }

    /** @return Traversable<int, Change> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->changes);
    }
}
