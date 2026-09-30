<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use function array_unique;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The mutators an extension registers under one set's name, each by its
 * class, which the gate constructs with no arguments.
 *
 * @implements IteratorAggregate<int, class-string<Mutator>>
 */
final readonly class MutatorSet implements Countable, IteratorAggregate
{
    /** @param list<class-string<Mutator>> $mutators */
    private function __construct(private array $mutators)
    {
    }

    /** @param class-string<Mutator> ...$mutators */
    public static function of(string ...$mutators): self
    {
        return new self(array_values(array_unique($mutators)));
    }

    public function count(): int
    {
        return count($this->mutators);
    }

    /** @return Traversable<int, class-string<Mutator>> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutators);
    }
}
