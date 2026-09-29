<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Numbers by name, in the order they were written: seconds per line by path
 * prefix, the lowest score of each badge colour.
 *
 * @implements IteratorAggregate<string, float>
 */
final readonly class Table implements IteratorAggregate
{
    /** @param array<string, float> $numbers */
    private function __construct(private array $numbers)
    {
    }

    /** @param array<string, float> $numbers */
    public static function of(array $numbers): self
    {
        return new self($numbers);
    }

    /** @return Traversable<string, float> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->numbers);
    }
}
