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
 * Warnings, in the order they were raised.
 *
 * @implements IteratorAggregate<int, Warning>
 */
final readonly class Warnings implements Countable, IteratorAggregate
{
    /** @param list<Warning> $warnings */
    private function __construct(private array $warnings) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Warning ...$warnings): self
    {
        return new self(array_values($warnings));
    }

    public function with(Warning $warning): self
    {
        return new self([...$this->warnings, $warning]);
    }

    public function count(): int
    {
        return count($this->warnings);
    }

    /** @return Traversable<int, Warning> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->warnings);
    }
}
