<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The keys of a run planned without a fresh map (ADR-0135): its verdict
 * merges the maps every shard's streams recorded into one, leaves it where
 * the verdict reads the run's map and where the map beside the ledger is
 * kept from, and builds each unit's key from it, as a plan builds keys from
 * the map it measured. Where any shard left no map, the merged one could
 * miss what that shard's tests executed, so it keys nothing, and the run
 * records no proof.
 *
 * @internal
 */
final readonly class EarlyKeys
{
    /** The plan keyed by its shards' recorded map, where it was planned early; as it is otherwise. */
    public static function of(Composed $composed, Plan $plan, Path $results): Plan|CannotJudge
    {
        if (! $plan->briefing()->isEarly()) {
            return $plan;
        }

        $map = self::recorded($composed, $plan, $results);

        if (! $map instanceof CoverageMap) {
            return $plan;
        }

        $text = Contents::of(CoverageMapFile::encode($map, Measuring::now($composed->adapters)));

        foreach ([Workspace::coverage(), Workspace::verdictCoverage()] as $directory) {
            $written = $composed->adapters->project->write(CoverageMapFile::in($directory), $text);

            if ($written instanceof CannotJudge) {
                return $written;
            }
        }

        $inventory = Inventory::of($composed->adapters, $composed->settings);
        $keying = $inventory instanceof Inventory ? Keying::of(
            $composed->adapters,
            $composed->settings,
            $composed->setup,
            $inventory->suite,
            MapJudges::of($composed->adapters->runner, $map),
        ) : $inventory;

        if (! $keying instanceof Keying) {
            return $keying;
        }

        $units = Units::none();

        foreach ($plan as $shard) {
            foreach ($shard->units() as $unit) {
                $units = $units->with($unit);
            }
        }

        return $plan->keyedBy($keying->base(), $keying->keysOf($units), $keying->digestsOf($units));
    }

    /** Every shard's recorded map, merged; none where any shard left none. */
    private static function recorded(Composed $composed, Plan $plan, Path $results): CoverageMap|CannotJudge
    {
        $map = CoverageMap::of();

        foreach ($plan as $shard) {
            $contents = $composed->adapters->project->read(Workspace::recorded($results, $shard->id()));
            $read = $contents instanceof Contents
                ? CoverageMapFile::decode($contents->text(), Handoff::limits())
                : CannotJudge::because('unrecorded');

            if (! $read instanceof CoverageMap) {
                return $read;
            }

            $map = $map->and($read);
        }

        return $map;
    }
}
