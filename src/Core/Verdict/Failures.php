<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_map;
use function array_values;

use ArrayIterator;

use function count;

use Countable;

use function implode;

use IteratorAggregate;
use Traversable;

/**
 * Failures, in the order they were found.
 *
 * @implements IteratorAggregate<int, Failure>
 */
final readonly class Failures implements Countable, IteratorAggregate
{
    /** @param list<Failure> $failures */
    private function __construct(private array $failures)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Failure ...$failures): self
    {
        return new self(array_values($failures));
    }

    public function with(Failure $failure): self
    {
        return new self([...$this->failures, $failure]);
    }

    /** These failures, then those. */
    public function and(self $those): self
    {
        return new self([...$this->failures, ...$those->failures]);
    }

    /** Every failure's sentence, one to a line. */
    public function text(): string
    {
        return implode("\n", array_map(static fn(Failure $failure): string => $failure->text(), $this->failures));
    }

    public function count(): int
    {
        return count($this->failures);
    }

    /** @return Traversable<int, Failure> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->failures);
    }
}
