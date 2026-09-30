<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use Traversable;

/**
 * What kept a run from judging honestly, in the order it was met. Any one of
 * them makes the verdict *cannot judge* (ADR-0016, decision 10).
 *
 * @implements IteratorAggregate<int, CannotJudge>
 */
final readonly class Obstacles implements Countable, IteratorAggregate
{
    /** @param list<CannotJudge> $obstacles */
    private function __construct(private array $obstacles)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function with(CannotJudge $obstacle): self
    {
        return new self([...$this->obstacles, $obstacle]);
    }

    public function count(): int
    {
        return count($this->obstacles);
    }

    /** @return Traversable<int, CannotJudge> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->obstacles);
    }
}
