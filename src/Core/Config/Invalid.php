<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A config that cannot be used, with every mistake in it at once.
 *
 * @implements IteratorAggregate<int, Problem>
 */
final readonly class Invalid implements Countable, IteratorAggregate
{
    /** @param list<Problem> $problems */
    private function __construct(private array $problems) {}

    public static function because(Problem $problem, Problem ...$more): self
    {
        return new self([$problem, ...array_values($more)]);
    }

    public function count(): int
    {
        return count($this->problems);
    }

    /** @return Traversable<int, Problem> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->problems);
    }
}
