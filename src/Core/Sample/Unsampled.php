<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The units a sample left out, each with the mutants it is estimated to
 * hold, which its tree's estimate counts (ADR-0020).
 *
 * @implements IteratorAggregate<int, Candidate>
 */
final readonly class Unsampled implements Countable, IteratorAggregate
{
    /** @param list<Candidate> $units */
    private function __construct(private array $units)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These units, and this one, with the mutants it is estimated to hold. */
    public function with(Candidate $unit): self
    {
        return new self([...$this->units, $unit]);
    }

    public function and(self $those): self
    {
        return new self([...$this->units, ...$those->units]);
    }

    public function count(): int
    {
        return count($this->units);
    }

    /** @return Traversable<int, Candidate> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->units);
    }
}
