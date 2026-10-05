<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * How each of several processes ended, in the order their commands were
 * given, whatever order they ended in.
 *
 * @implements IteratorAggregate<int, Ran>
 */
final readonly class ProcessEnds implements Countable, IteratorAggregate
{
    /** @param list<Ran> $ends */
    private function __construct(private array $ends)
    {
    }

    public static function of(Ran ...$ends): self
    {
        return new self(array_values($ends));
    }

    public function count(): int
    {
        return count($this->ends);
    }

    /** @return Traversable<int, Ran> */
    public function getIterator(): Traversable
    {
        yield from $this->ends;
    }
}
