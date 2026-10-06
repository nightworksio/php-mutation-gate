<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Mutants, in the order they were reported.
 *
 * @implements IteratorAggregate<int, Mutant>
 */
final readonly class Mutants implements Countable, IteratorAggregate
{
    /** @param list<Mutant> $mutants */
    private function __construct(private array $mutants)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Mutant ...$mutants): self
    {
        return new self(array_values($mutants));
    }

    public function with(Mutant $mutant): self
    {
        return new self([...$this->mutants, $mutant]);
    }

    /** These mutants, each one of those holds by its id replaced by that one. */
    public function replacing(self $those): self
    {
        $by = [];

        foreach ($those->mutants as $mutant) {
            $by[$mutant->id()->key()] = $mutant;
        }

        return new self(array_map(
            static fn(Mutant $mutant): Mutant => array_key_exists($mutant->id()->key(), $by)
                ? $by[$mutant->id()->key()]
                : $mutant,
            $this->mutants,
        ));
    }

    public function count(): int
    {
        return count($this->mutants);
    }

    /** How many of these mutants have this status. */
    public function counting(MutantStatus $status): int
    {
        return count(array_filter($this->mutants, static fn(Mutant $mutant): bool => $mutant->status() === $status));
    }

    /** @return Traversable<int, Mutant> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutants);
    }
}
