<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Cluster\Clusters;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use Traversable;

/**
 * Every judged tree, in the order it was judged, and the clusters its
 * survivors are in.
 *
 * @implements IteratorAggregate<int, TreeVerdict>
 */
final readonly class TreeVerdicts implements Countable, IteratorAggregate
{
    private Clusters $clusters;

    /** @param list<TreeVerdict> $verdicts */
    private function __construct(private array $verdicts)
    {
        $this->clusters = Clusters::of($this->mutants());
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

    /**
     * These trees, with the survivors of one cause marked with their cluster,
     * read from each file's source (ADR-0022, decision 15).
     *
     * @param ByPath<Contents> $sources each file of their survivors that can be read, by its path
     */
    public function clustered(ByPath $sources): self
    {
        $clustered = [];

        foreach ($this->verdicts as $tree) {
            $clustered[] = $tree->clustered($sources);
        }

        return new self($clustered);
    }

    /** These trees, with what was found of each survivor (ADR-0025, decision 7). */
    public function found(Findings $findings): self
    {
        $found = [];

        foreach ($this->verdicts as $tree) {
            $found[] = $tree->found($findings);
        }

        return new self($found);
    }

    /** The clusters the survivors of these trees are in; none before they are clustered. */
    public function clusters(): Clusters
    {
        return $this->clusters;
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
