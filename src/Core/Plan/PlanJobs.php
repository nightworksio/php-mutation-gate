<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function in_array;
use function ksort;

/**
 * The jobs that run a plan, each with the labels of the shards listed first
 * for it: one a shard, unless its shards pull their work (ADR-0134).
 *
 * @internal
 */
final readonly class PlanJobs
{
    /** @return array<int, list<string>> each job's labels, by its number */
    public static function of(Plan $plan): array
    {
        $jobs = [];

        foreach ($plan as $shard) {
            $number = $shard->worker()->number();
            $labels = array_key_exists($number, $jobs) ? $jobs[$number] : [];
            $jobs[$number] = in_array($shard->label(), $labels, true) ? $labels : [...$labels, $shard->label()];
        }

        ksort($jobs);

        return $jobs;
    }
}
