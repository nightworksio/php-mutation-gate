<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Unit results, in the order they were added.
 *
 * @implements IteratorAggregate<int, UnitResult>
 */
final readonly class UnitResults implements Countable, IteratorAggregate
{
    /** @param list<UnitResult> $results */
    private function __construct(private array $results)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(UnitResult ...$results): self
    {
        return new self(array_values($results));
    }

    public function with(UnitResult $result): self
    {
        return new self([...$this->results, $result]);
    }

    /** These results, then those. */
    public function and(self $those): self
    {
        return new self([...$this->results, ...$those->results]);
    }

    public function count(): int
    {
        return count($this->results);
    }

    /** @return Traversable<int, UnitResult> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->results);
    }
}
