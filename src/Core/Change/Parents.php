<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

use function array_values;

use ArrayIterator;

use function count;

use IteratorAggregate;
use Traversable;

/**
 * The commits a commit was made from, in its order: none for the first
 * commit, one for most, and two for a merge, whose first is the branch it
 * merged into.
 *
 * @implements IteratorAggregate<int, Revision>
 */
final readonly class Parents implements IteratorAggregate
{
    /** The number of parents a merge of two commits has. */
    private const int OF_A_MERGE = 2;

    /** @param list<Revision> $parents */
    private function __construct(private array $parents)
    {
    }

    public static function of(Revision ...$parents): self
    {
        return new self(array_values($parents));
    }

    /** Whether these are the two commits a merge was made from. */
    public function areOfAMerge(): bool
    {
        return count($this->parents) === self::OF_A_MERGE;
    }

    /** @return Traversable<int, Revision> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->parents);
    }
}
