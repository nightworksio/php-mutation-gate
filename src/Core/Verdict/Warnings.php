<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_filter;
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
    private function __construct(private array $warnings)
    {
    }

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

    /** These warnings, then those. */
    public function and(self $other): self
    {
        return new self([...$this->warnings, ...$other->warnings]);
    }

    /** Those someone should act on, as the Warnings of a report lists them. */
    public function actionable(): self
    {
        return new self(array_values(array_filter(
            $this->warnings,
            static fn(Warning $warning): bool => ! $warning->isNote(),
        )));
    }

    /** Those that ask nothing of anyone, as the Notes of a report lists them. */
    public function notes(): self
    {
        return new self(array_values(array_filter(
            $this->warnings,
            static fn(Warning $warning): bool => $warning->isNote(),
        )));
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
