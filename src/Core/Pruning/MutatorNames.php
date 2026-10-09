<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

use function array_unique;
use function array_values;

use ArrayIterator;

use function count;

use Countable;

use function in_array;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;

use function sort;

use Traversable;

/**
 * Mutators by the runner's names for them, sorted, such as those pruned or
 * those never pruned (ADR-0025, decision 2).
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class MutatorNames implements Countable, IteratorAggregate
{
    /** @param list<string> $names */
    private function __construct(private array $names)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(string ...$names): self
    {
        $sorted = array_values(array_unique($names));
        sort($sorted);

        return new self($sorted);
    }

    public function has(RunnerMutatorName $name): bool
    {
        return in_array($name->value(), $this->names, strict: true);
    }

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
