<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * What the jobs of a second wave that judged shares of what one shard left
 * measured of their own time (ADR-0196), in the order their results were
 * read.
 *
 * @implements IteratorAggregate<int, JobTimes>
 */
final readonly class SharesTimed implements Countable, IteratorAggregate
{
    /** @param list<JobTimes> $shares */
    private function __construct(private array $shares)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, and these after them. */
    public function and(JobTimes ...$shares): self
    {
        return new self([...$this->shares, ...array_values($shares)]);
    }

    public function count(): int
    {
        return count($this->shares);
    }

    /** @return Traversable<int, JobTimes> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->shares);
    }
}
