<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Testing;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The changes a runner's bridge makes to a snippet, one per mutant, in the
 * order the nodes stand: each the lines it removes, `-` first, then the lines
 * that replace them, `+` first.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class Changes implements Countable, IteratorAggregate
{
    /** @param list<string> $changes */
    private function __construct(private array $changes)
    {
    }

    public static function of(string ...$changes): self
    {
        return new self(array_values($changes));
    }

    public function count(): int
    {
        return count($this->changes);
    }

    /** @return Traversable<int, string> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->changes);
    }
}
