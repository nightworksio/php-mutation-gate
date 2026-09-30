<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Every judged tree, in the order it was judged.
 *
 * @implements IteratorAggregate<int, TreeVerdict>
 */
final readonly class TreeVerdicts implements Countable, IteratorAggregate
{
    /** @param list<TreeVerdict> $verdicts */
    private function __construct(private array $verdicts)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(TreeVerdict ...$verdicts): self
    {
        return new self(array_values($verdicts));
    }

    public function with(TreeVerdict $verdict): self
    {
        return new self([...$this->verdicts, $verdict]);
    }

    /** Every unit of every tree, tree by tree. */
    public function units(): JudgedUnits
    {
        $units = JudgedUnits::none();

        foreach ($this->verdicts as $tree) {
            $units = $units->and($tree->units());
        }

        return $units;
    }

    /** Every mutant of every tree, tree by tree. */
    public function mutants(): JudgedMutants
    {
        $mutants = JudgedMutants::none();

        foreach ($this->verdicts as $tree) {
            $mutants = $mutants->and($tree->mutants());
        }

        return $mutants;
    }

    public function count(): int
    {
        return count($this->verdicts);
    }

    /** @return Traversable<int, TreeVerdict> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->verdicts);
    }
}
