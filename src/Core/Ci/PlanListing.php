<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\JsonText;
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
        return JsonText::encode(self::listed($plan));
    }

    /** The same listing on one line, as `$GITHUB_OUTPUT` takes a value. */
    public static function inline(Plan $plan): string
    {
        return JsonText::compact(self::listed($plan));
    }

    /**
     * @return array{
     *     plan: string,
     *     commit: string,
     *     shards: list<array{id: int, label: string, seconds: int, units: list<string>}>,
     * }
     */
    private static function listed(Plan $plan): array
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

        return [
            'plan' => $plan->digest()->value(),
            'commit' => $plan->commit()->name(),
            'shards' => $shards,
        ];
    }
}
