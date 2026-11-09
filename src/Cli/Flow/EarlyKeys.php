<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\GroupCoverage;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\NotGiven;
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
    /** Every shard's recorded map merged, where the plan was planned early and every shard left one; none otherwise. */
    public static function recorded(Composed $composed, Plan $plan, Path $results): CoverageMap|NotGiven
    {
        if (! $plan->briefing()->isEarly()) {
            return NotGiven::value();
        }

        $map = CoverageMap::of();

        foreach ($plan as $shard) {
            $contents = $composed->adapters->project->read(Workspace::recorded($results, $shard->id()));
            $read = $contents instanceof Contents
                ? CoverageMapFile::decode($contents->text(), Handoff::limits())
                : NotGiven::value();

            if (! $read instanceof CoverageMap) {
                return NotGiven::value();
            }

            $map = $map->and($read);
        }

        return $map;
    }

    /**
     * Each held unit whose holding tests, as its shard kept them, miss a line
     * the recorded map shows the suite covering: in a run planned early the
     * verdict, not the shard, checks them (ADR-0135).
     */
    public static function misses(Results $results, CoverageMap $map): HeldMisses
    {
        $misses = HeldMisses::none();

        foreach ($results->shards() as [, $result]) {
            foreach ($result->held()->covered() as $covered) {
                $checked = GroupCoverage::of($covered->unit(), $map, $map->ranBy($covered->tests()));
                $misses = $checked instanceof NotCovered ? $misses->with($checked) : $misses;
            }
        }

        return $misses;
    }

    /** The plan keyed by its shards' recorded map, where it has one; as it is otherwise. */
    public static function of(Composed $composed, Plan $plan, CoverageMap|NotGiven $map): Plan|CannotJudge
    {
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
}
