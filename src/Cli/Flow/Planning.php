<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * `plan`: the units of the trees, what the change reaches, each considered
 * unit's key, and the shards the units no proof covers are cut into.
 */
final readonly class Planning
{
    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    public function plan(Mode $mode, CoverageRequest $coverage, Cut $cut): Plan|CannotJudge
    {
        $inventory = Inventory::of($this->adapters, $this->settings);

        if ($inventory instanceof CannotJudge) {
            return $inventory;
        }

        $map = $this->adapters->runner->coverage($coverage->withholding($this->adapters->withheld));

        if ($map instanceof CannotJudge) {
            return $map;
        }

        $keying = Keying::of($this->adapters, $this->settings, $this->setup, $inventory->suite, $map);

        return $keying instanceof Keying ? $this->planned($inventory, $map, $keying, $mode, $cut) : $keying;
    }

    private function planned(
        Inventory $inventory,
        CoverageMap $map,
        Keying $keying,
        Mode $mode,
        Cut $cut,
    ): Plan|CannotJudge {
        $writing = Writing::from($this->settings->proofs()->write()->value);
        $ledgers = Ledgers::read($this->adapters->proofs, $inventory->standing, $writing);
        $base = $mode->base($ledgers);
        $reached = $base instanceof Revision
            ? Reached::since($base, $inventory->trees, $this->adapters, $this->settings, $inventory->suite, $map)
            : Reached::everything($inventory->trees, $base);
        $considering = Considering::of(
            $inventory->units,
            $reached->reach(),
            $ledgers->defaultBranch()->proofs(),
            $ledgers->own()->proofs(),
        );
        $keys = $keying->keysOf($considering->considered());
        $proving = $ledgers->proving($considering->considered(), $keys, $keying->base());
        $shards = $cut->cut($this->workload($proving->toRun(), $inventory->trees, $ledgers), $inventory->trees);

        if ($shards instanceof CannotJudge) {
            return $shards;
        }

        $plan = Plan::of(
            $inventory->standing->head(),
            $keying->base(),
            $keys,
            $shards,
        )
            ->on($inventory->standing->runOn())
            ->reaching($reached->changed(), $reached->reach()->reasons())
            ->proving($this->unitsOf($proving->proved()))
            ->carrying($this->unitsOf($considering->carried()));
        $handed = new Handoff($this->adapters->project)->write($plan, $map);

        return $handed instanceof CannotJudge ? $handed : $plan;
    }

    private function unitsOf(UnitResults $results): Units
    {
        $units = Units::none();

        foreach ($results as $result) {
            $units = $units->with($result->unit());
        }

        return $units;
    }

    /** Each unit to run, in the package its tree is in, weighed by what the cost model expects of it. */
    private function workload(Units $units, Trees $trees, Ledgers $ledgers): Workload
    {
        $weighed = [];

        foreach ($units as $unit) {
            $tree = $trees->holding($unit->path());

            if ($tree instanceof Tree) {
                $weighed[] = Weighed::of(
                    $unit,
                    $tree->package(),
                    $this->adapters->costs->cost($unit, $ledgers->timings()),
                );
            }
        }

        return Workload::of(...$weighed);
    }
}
