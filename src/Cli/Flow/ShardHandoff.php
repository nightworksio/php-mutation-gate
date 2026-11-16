<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Written;

/**
 * Everything the plan readies once for every shard: the maps and the kill
 * history it hands them (see Handoff), the map every shard opens on where
 * the runner readies it (ADR-0163), and the static analyser warmed up over
 * the originals where they check mutants with it (ADR-0164).
 *
 * @internal
 */
final readonly class ShardHandoff
{
    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** What the plan readied for its shards, or why it could not hand them their maps. */
    public function of(
        Plan $plan,
        CoverageMap $map,
        KillHistory $history,
        MeasuredAt|Unplaced $at,
        EntryKeys|NotGiven $keys,
        Subsumption|NotGiven $held,
    ): Written|Missing|CannotJudge {
        $opened = $this->adapters->runner->behaviour()->opening()
            ->readied($map, $plan->shards()->paths(), $this->adapters->suite->testCases());
        $handed = new Handoff(
            $this->adapters->project,
            Handoff::limits(),
            $this->adapters->maps,
            $this->settings->proofs()->coverageCompressionLevel(),
        )
            ->write($plan, $map, $history, $at, $keys, $held, $opened);

        return $handed instanceof CannotJudge
            ? $handed
            : new PlannedWarmUp($this->adapters, $this->settings, $this->setup->clock)->left($plan);
    }
}
