<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_diff_key;
use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Mutants by the gate's id, each once, in the order they were added.
 *
 * @implements IteratorAggregate<int, MutantId>
 */
final readonly class MutantIds implements Countable, IteratorAggregate
{
    /** @param array<string, MutantId> $ids by value */
    private function __construct(private array $ids)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(MutantId ...$ids): self
    {
        $collected = [];

        foreach ($ids as $id) {
            $collected[$id->value()] = $id;
        }

        return new self($collected);
    }

    /** These ids, then those not among them. */
    public function and(self $those): self
    {
        return new self($this->ids + $those->ids);
    }

    /** These ids, less those. */
    public function without(self $those): self
    {
        return new self(array_diff_key($this->ids, $those->ids));
    }

    public function has(MutantId $id): bool
    {
        return array_key_exists($id->value(), $this->ids);
    }

    public function count(): int
    {
        return count($this->ids);
    }

    /** @return Traversable<int, MutantId> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->ids));
    }
}
