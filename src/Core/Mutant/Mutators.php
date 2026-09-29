<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_unique;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Which mutators a run applies: every one the runner has, or only these, by
 * the runner's full names for them.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class Mutators implements Countable, IteratorAggregate
{
    /** @param list<string> $names */
    private function __construct(private array $names)
    {
    }

    public static function all(): self
    {
        return new self([]);
    }

    public static function named(string $name, string ...$more): self
    {
        return new self(array_values(array_unique([$name, ...$more])));
    }

    public function isAll(): bool
    {
        return $this->names === [];
    }

    /** How many are named; every one the runner has is none named. */
    public function count(): int
    {
        return count($this->names);
    }

    /** @return Traversable<int, string> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->names);
    }
}
