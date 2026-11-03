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
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Sample\Estimate;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use Traversable;

/**
 * Every judged tree, in the order it was judged, and the clusters its
 * survivors are in; or none, where the verdict of a commit that passed
 * stands in their place ({@see Unchanged}).
 *
 * @implements IteratorAggregate<int, TreeVerdict>
 */
final readonly class TreeVerdicts implements Countable, IteratorAggregate
{
    private Clusters $clusters;

    /** @param list<TreeVerdict> $verdicts */
    private function __construct(private array $verdicts, private Unchanged|NotGiven $standing)
    {
        $this->clusters = Clusters::of($this->mutants());
    }

    public static function none(): self
    {
        return new self([], NotGiven::value());
    }

    public static function of(TreeVerdict ...$verdicts): self
    {
        return new self(array_values($verdicts), NotGiven::value());
    }

    /** No judged tree: the verdict of the commit that passed stands in their place. */
    public static function standingOn(Unchanged $unchanged): self
    {
        return new self([], $unchanged);
    }

    public function with(TreeVerdict $verdict): self
    {
        return new self([...$this->verdicts, $verdict], $this->standing);
    }

    /** The verdict of the commit that passed that stands in place of these trees; none where they were judged. */
    public function standing(): Unchanged|NotGiven
    {
        return $this->standing;
    }

    /**
     * These trees, with the survivors of one cause marked with their cluster,
     * read from each file's source (ADR-0022).
     *
     * @param ByPath<Contents> $sources each file of their survivors that can be read, by its path
     */
    public function clustered(ByPath $sources): self
    {
        $clustered = [];

        foreach ($this->verdicts as $tree) {
            $clustered[] = $tree->withMutants($tree->mutants()->clustered($tree->uncovered(), $sources));
        }

        return new self($clustered, $this->standing);
    }

    /** These trees, each mutant's hint naming its judging tests by the names their runner gave them. */
    public function named(TestNames $names): self
    {
        $named = [];

        foreach ($this->verdicts as $tree) {
            $named[] = $tree->withMutants($tree->mutants()->named($names));
        }

        return new self($named, $this->standing);
    }

    /** These trees, with what was found of each survivor (ADR-0025). */
    public function found(Findings $findings): self
    {
        $found = [];

        foreach ($this->verdicts as $tree) {
            $found[] = $tree->withMutants($tree->mutants()->found($findings));
        }

        return new self($found, $this->standing);
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

    /** The sample the run drew, which every tree's estimate names; none where the run judged every tree whole. */
    public function sample(): Sample
    {
        foreach ($this->verdicts as $tree) {
            $estimate = $tree->estimate();

            if ($estimate instanceof Estimate) {
                return $estimate->sample();
            }
        }

        return Sample::none();
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
