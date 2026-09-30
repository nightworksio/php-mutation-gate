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
 * Judged units, in the order they were added.
 *
 * @implements IteratorAggregate<int, JudgedUnit>
 */
final readonly class JudgedUnits implements Countable, IteratorAggregate
{
    /** @param list<JudgedUnit> $units */
    private function __construct(private array $units)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(JudgedUnit ...$units): self
    {
        return new self(array_values($units));
    }

    public function with(JudgedUnit $unit): self
    {
        return new self([...$this->units, $unit]);
    }

    /** These units, then those. */
    public function and(self $those): self
    {
        return new self([...$this->units, ...$those->units]);
    }

    public function count(): int
    {
        return count($this->units);
    }

    /** @return Traversable<int, JudgedUnit> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->units);
    }
}
