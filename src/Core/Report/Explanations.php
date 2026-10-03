<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_values;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use Traversable;

/**
 * What `explain` explains: one mutant, or a cluster of survivors with each
 * of its members (ADR-0022, decision 17).
 *
 * @implements IteratorAggregate<int, Explanation>
 */
final readonly class Explanations implements IteratorAggregate
{
    /** @param non-empty-list<Explanation> $explanations */
    private function __construct(private Cluster|Unclustered $cluster, private array $explanations)
    {
    }

    public static function ofMutant(Explanation $explanation): self
    {
        return new self(Unclustered::mutant(), [$explanation]);
    }

    /** A cluster, with each member explained, its first survivor first. */
    public static function ofCluster(Cluster $cluster, Explanation $first, Explanation ...$more): self
    {
        return new self($cluster, [$first, ...array_values($more)]);
    }

    /** The mutant explained, or a cluster's first survivor, which `stub` writes for (ADR-0022, decision 16). */
    public function first(): Explanation
    {
        return $this->explanations[0];
    }

    /** The cluster explained; none where one mutant is. */
    public function cluster(): Cluster|Unclustered
    {
        return $this->cluster;
    }

    /** @return Traversable<int, Explanation> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->explanations);
    }
}
