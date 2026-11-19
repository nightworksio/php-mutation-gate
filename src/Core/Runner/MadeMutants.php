<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Which of its mutants a run makes: every one of its files and mutators, or
 * only those with these native ids, as a job of the second wave judges a
 * share of the mutants a shard left (ADR-0190). A runner that cannot make
 * only some makes every one, and the gate keeps those it asked for.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class MadeMutants implements Countable, IteratorAggregate
{
    /** @param list<string> $natives */
    private function __construct(private array $natives)
    {
    }

    public static function every(): self
    {
        return new self([]);
    }

    public static function only(string ...$natives): self
    {
        return new self(array_values($natives));
    }

    /** How many it makes alone; none where it makes every one. */
    public function count(): int
    {
        return count($this->natives);
    }

    /** @return Traversable<int, string> the native ids of the only mutants it makes */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->natives);
    }
}
