<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

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

    /**
     * The mutants whose status here differs from theirs in those, or that
     * only one of the two has: the answers two runs of the same code do not
     * agree on.
     */
    public function disagreeingWith(self $those): MutantIds
    {
        $theirs = [];
        $differ = [];

        foreach ($those->mutants as $mutant) {
            $theirs[$mutant->id()->value()] = $mutant;
        }

        foreach ($this->mutants as $mutant) {
            $id = $mutant->id()->value();
            $agrees = array_key_exists($id, $theirs) && $theirs[$id]->status() === $mutant->status();
            $differ = $agrees ? $differ : [...$differ, $mutant->id()];
            unset($theirs[$id]);
        }

        $onlyTheirs = array_map(static fn(Mutant $mutant): MutantId => $mutant->id(), array_values($theirs));

        return MutantIds::of(...$differ, ...$onlyTheirs);
    }

    public function count(): int
    {
        return count($this->mutants);
    }

    /** @return Traversable<int, Mutant> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutants);
    }
}
