<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Trees, in the order they were found.
 *
 * @implements IteratorAggregate<int, Tree>
 */
final readonly class Trees implements Countable, IteratorAggregate
{
    /** @param list<Tree> $trees */
    private function __construct(private array $trees)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Tree ...$trees): self
    {
        return new self(array_values($trees));
    }

    public function with(Tree $tree): self
    {
        return new self([...$this->trees, $tree]);
    }

    public function count(): int
    {
        return count($this->trees);
    }

    /** @return Traversable<int, Tree> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->trees);
    }
}
