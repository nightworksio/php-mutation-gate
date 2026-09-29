<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function round;

/**
 * A plan as the generic JSON a CI without a format of its own reads:
 * `{"plan": "<digest>", "commit": "<sha>", "shards": [{"id": 1, "label": "…",
 * "seconds": 540, "units": ["src/A.php", …]}]}`, each shard's seconds the
 * cost model's estimate to the whole second.
 */
final readonly class PlanListing
{
    public static function of(Plan $plan): string
    {
        $shards = [];

        foreach ($plan as $shard) {
            $shards[] = [
                'id' => $shard->id()->number(),
                'label' => $shard->label(),
                'seconds' => (int) round($shard->cost()->seconds()),
                'units' => array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$shard->units()]),
            ];
        }

        return Json::encode([
            'plan' => $plan->digest()->value(),
            'commit' => $plan->commit()->name(),
            'shards' => $shards,
        ]);
    }
}
