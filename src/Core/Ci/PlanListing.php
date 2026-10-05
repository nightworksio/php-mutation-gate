<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function array_diff_key;
use function array_map;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function round;

/**
 * A plan as the generic JSON a CI without a format of its own reads:
 * `{"plan": "<digest>", "commit": "<sha>", "shards": [{"id": 1, "label": "…",
 * "seconds": 540, "units": ["src/A.php", …]}]}`, each shard's seconds the
 * cost model's estimate to the whole second. On one line, it lists no units.
 */
final readonly class PlanListing
{
    /** The listing as a CI's log may show it: indented, and inert there ({@see JsonText::printed()}). */
    public static function of(Plan $plan): string
    {
        return JsonText::printed(self::listed($plan));
    }

    /**
     * The same listing on one line, as `$GITHUB_OUTPUT` takes a value, each shard without its units: the plan file
     * carries them, and a job's outputs hold at most 1 MB, which a plan of tens of thousands of units would pass.
     */
    public static function inline(Plan $plan): string
    {
        $listed = self::listed($plan);
        $shards = [];

        foreach ($listed['shards'] as $shard) {
            $shards[] = array_diff_key($shard, ['units' => true]);
        }

        return JsonText::compact([...$listed, 'shards' => $shards]);
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
