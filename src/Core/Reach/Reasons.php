<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The decisions about what a change reaches, in the order they were made.
 *
 * @implements IteratorAggregate<int, Reason>
 */
final readonly class Reasons implements Countable, IteratorAggregate
{
    /** @param list<Reason> $reasons */
    private function __construct(private array $reasons)
    {
    }

    public static function of(Reason ...$reasons): self
    {
        return new self(array_values($reasons));
    }

    public function with(Reason $reason): self
    {
        return new self([...$this->reasons, $reason]);
    }

    public function count(): int
    {
        return count($this->reasons);
    }

    /** @return Traversable<int, Reason> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->reasons);
    }
}
