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
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * The keys of a run planned without a fresh map (ADR-0135): its verdict
 * merges the maps every shard's streams recorded into one, leaves it where
 * the verdict reads the run's map and where the map beside the ledger is
 * kept from, and builds each unit's key from it, as a plan builds keys from
 * the map it measured. Where any shard left no map, or one that cannot be
 * read, the run cannot be judged: its held units would go unchecked and its
 * keys would miss what that shard's tests executed.
 *
 * @internal
 */
final readonly class EarlyKeys
{
    private const string UNRECORDED = <<<'SAID'
        The run was planned without a fresh map, and shard %d left no map of what its streams executed at %s,
        so its held units and keys cannot be judged.
        SAID;

    private const string UNREAD
        = 'The run was planned without a fresh map, and the map shard %d recorded cannot be judged by: %s';

    /**
     * Every shard's recorded map merged, where the plan was planned early;
     * why the run cannot be judged where any shard left none, or one that
     * cannot be read, since its held units would go unchecked and its keys
     * would miss what that shard's tests executed; none where the plan was
     * not planned early.
     */
    public static function recorded(Composed $composed, Plan $plan, Path $results): CoverageMap|CannotJudge|NotGiven
    {
        if (! $plan->briefing()->isEarly()) {
            return NotGiven::value();
        }

        $map = CoverageMap::of();

        foreach ($plan as $shard) {
            $read = self::recordedBy($composed, $shard, $results);

            if ($read instanceof CannotJudge) {
                return $read;
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

        $keying = self::keyingBy($composed, $map);
        $units = self::unitsOf($plan);

        return $keying instanceof Keying
            ? $plan->keyedBy($keying->base(), $keying->keysOf($units), $keying->digestsOf($units))
            : $keying;
    }

    /** The map a shard recorded; or why it left none that can be read. */
    private static function recordedBy(Composed $composed, Shard $shard, Path $results): CoverageMap|CannotJudge
    {
        $file = Workspace::recorded($results, $shard->id());
        $contents = $composed->adapters->project->read($file);

        if (! $contents instanceof Contents) {
            return CannotJudge::because(sprintf(self::UNRECORDED, $shard->id()->number(), $file->value()));
        }

        $read = CoverageMapFile::decode($contents->text(), Handoff::limits());

        return $read instanceof CoverageMap
            ? $read
            : CannotJudge::because(sprintf(self::UNREAD, $shard->id()->number(), $read->why()));
    }

    /** The keying of a map, written first where the verdict reads the run's coverage; or why there is none. */
    private static function keyingBy(Composed $composed, CoverageMap $map): Keying|CannotJudge
    {
        $text = Contents::of(CoverageMapFile::encode($map, Measuring::now($composed->adapters)));

        foreach ([Workspace::coverage(), Workspace::verdictCoverage()] as $directory) {
            $written = $composed->adapters->project->write(CoverageMapFile::in($directory), $text);

            if ($written instanceof CannotJudge) {
                return $written;
            }
        }

        $inventory = Inventory::of($composed->adapters, $composed->settings);

        return $inventory instanceof Inventory ? Keying::of(
            $composed->adapters,
            $composed->settings,
            $composed->setup,
            $inventory->suite,
            MapJudges::of($composed->adapters->runner, $map),
        ) : $inventory;
    }

    /** Every unit of a plan's shards. */
    private static function unitsOf(Plan $plan): Units
    {
        $units = Units::none();

        foreach ($plan as $shard) {
            foreach ($shard->units() as $unit) {
                $units = $units->with($unit);
            }
        }

        return $units;
    }
}
