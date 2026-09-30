<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cluster\Clusters;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

/**
 * Mutants as a list shows them: a cluster as one item, in the place of the
 * first of its members met, and its other members left out, so one test that
 * would fix several is asked for once (ADR-0022, decision 17).
 */
final readonly class Folded
{
    /**
     * @param  iterable<JudgedMutant>    $mutants
     * @return list<JudgedMutant|Cluster>
     */
    public static function of(iterable $mutants, Clusters $clusters): array
    {
        $items = [];
        $shown = [];

        foreach ($mutants as $judged) {
            $cluster = $clusters->clusterOf($judged);

            if ($cluster instanceof Unclustered) {
                $items[] = $judged;

                continue;
            }

            if (! array_key_exists($cluster->id()->value(), $shown)) {
                $items[] = $cluster;
                $shown[$cluster->id()->value()] = true;
            }
        }

        return $items;
    }
}
