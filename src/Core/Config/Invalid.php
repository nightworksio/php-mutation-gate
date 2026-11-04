<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function array_values;

use ArrayIterator;

use function count;

use Countable;

use function implode;

use IteratorAggregate;
use Traversable;

/**
 * A config that cannot be used, with every mistake in it at once.
 *
 * @implements IteratorAggregate<int, Problem>
 */
final readonly class Invalid implements Countable, IteratorAggregate
{
    /** @param list<Problem> $problems */
    private function __construct(private array $problems)
    {
    }

    public static function because(Problem $problem, Problem ...$more): self
    {
        return new self([$problem, ...array_values($more)]);
    }

    /** Every problem as one line says it, each `path: message`, one after another. */
    public function said(): string
    {
        return implode(' ', array_map(static fn(Problem $problem): string => $problem->said(), $this->problems));
    }

    public function count(): int
    {
        return count($this->problems);
    }

    /** @return Traversable<int, Problem> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->problems);
    }
}
