<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

use function array_key_exists;
use function array_slice;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use Traversable;

use function usort;

/**
 * The clusters some mutants are in, gathered from the cluster each member
 * carries, in the order of their representatives: by file, then line, then
 * id. A kill a ledger proved is in none.
 *
 * @implements IteratorAggregate<int, Cluster>
 */
final readonly class Clusters implements Countable, IteratorAggregate
{
    /**
     * @param list<Cluster>          $clusters
     * @param array<string, Cluster> $of       each member's cluster, by the member's id
     */
    private function __construct(private array $clusters, private array $of)
    {
    }

    public static function of(JudgedMutants $mutants): self
    {
        $members = [];
        $memberships = [];

        foreach ($mutants as $judged) {
            $in = $judged instanceof JudgedMutant ? $judged->cluster() : Unclustered::mutant();

            if ($in instanceof Membership) {
                $members[$in->id()->value()][] = $judged;
                $memberships[$in->id()->value()] = $in;
            }
        }

        $clusters = [];
        $of = [];

        foreach ($members as $id => $listed) {
            $cluster = Cluster::of($memberships[$id], $listed[0], ...array_slice($listed, 1));
            $clusters[] = $cluster;

            foreach ($listed as $member) {
                $of[$member->mutant()->id()->value()] = $cluster;
            }
        }

        usort($clusters, static fn(Cluster $one, Cluster $other): int => self::order($one) <=> self::order($other));

        return new self($clusters, $of);
    }

    /** The cluster a mutant is in, among these. */
    public function clusterOf(JudgedMutant $judged): Cluster|Unclustered
    {
        $id = $judged->mutant()->id()->value();

        return array_key_exists($id, $this->of) ? $this->of[$id] : Unclustered::mutant();
    }

    public function count(): int
    {
        return count($this->clusters);
    }

    /** @return Traversable<int, Cluster> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->clusters);
    }

    /** @return array{string, int, string} where a cluster's representative stands: its file, line and id */
    private static function order(Cluster $cluster): array
    {
        $mutant = $cluster->representative()->mutant();

        return [$mutant->location()->file()->value(), $mutant->location()->start()->number(), $mutant->id()->value()];
    }
}
