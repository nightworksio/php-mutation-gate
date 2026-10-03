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
    /** @param list<Explanation> $explanations */
    private function __construct(private Cluster|Unclustered $cluster, private array $explanations)
    {
    }

    public static function ofMutant(Explanation $explanation): self
    {
        return new self(Unclustered::mutant(), [$explanation]);
    }

    public static function ofCluster(Cluster $cluster, Explanation ...$members): self
    {
        return new self($cluster, array_values($members));
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
