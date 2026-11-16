<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\RiskOrder;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;

use function sprintf;

/**
 * The shards a plan cuts the units it runs into: each of the project's root
 * package, since the runner runs the root package's suite, which does not
 * judge another package's code; each unit weighed by what the cost model
 * expects of it; each shard's units the riskiest first, the order a budget
 * runs them in.
 *
 * @internal
 */
final readonly class PlannedShards
{
    private const string PACKAGED = <<<'SAID'
        The package at %s has units to mutate, and the runner runs the suite of the project's root alone,
        which does not judge another package's code, so their mutants cannot be judged.
        SAID;


    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /**
     * The units to run cut into shards, each of the project's root package,
     * where the runner has no ignore marker in them the config refuses, and
     * each shard's units the riskiest first, the order a budget runs them in,
     * and each expecting its runner to pay this opening run first.
     */
    public function of(
        Units $toRun,
        Trees $trees,
        Ledgers $ledgers,
        FirstRun $firstRun,
        Cut $cut,
        Seconds $opening,
        RiskOrder $order,
    ): Shards|CannotJudge {
        $unmarked = new RunnerMarkers($this->adapters, $this->settings)->refusing($toRun);
        $shards = $unmarked instanceof Units
            ? $cut->opening($opening)->cut($this->workload($unmarked, $trees, $ledgers, $firstRun), $trees)
            : $unmarked;
        $rooted = $shards instanceof Shards ? $this->rooted($shards, $order) : $shards;

        return $rooted instanceof Shards ? $this->opened($rooted, $opening) : $rooted;
    }

    /**
     * The order a budget takes these units in, knowing when each of the least
     * risky last changed; by path among those where git cannot tell. A run
     * that knows no change, as a full one does not, reaches no unit by one.
     */
    public function riskOrder(Reached $reached, Trees $trees, NewestProofs $newest, Units $units): RiskOrder
    {
        $order = RiskOrder::of(
            $reached->changed() instanceof Changes ? $reached->reach() : Reach::nothing(Packages::of($trees)),
            $newest,
            MutantTriage::under($this->settings->triage()->timeouts(), $this->settings->triage()->headroom()),
        );
        $changed = $this->adapters->changes->lastChanged($order->least($units));

        return $changed instanceof ByPath ? $order->knowing($changed) : $order;
    }

    /** The shards, each expecting its runner to pay this opening run first. */
    private function opened(Shards $shards, Seconds $opening): Shards
    {
        $opened = [];

        foreach ($shards as $shard) {
            $opened[] = $shard->estimated($shard->estimate()->opening($opening));
        }

        return Shards::of(...$opened);
    }

    /** The shards, each in this order, where each is of the project's root package. */
    private function rooted(Shards $shards, RiskOrder $order): Shards|CannotJudge
    {
        $ordered = Shards::none();

        foreach ($shards as $shard) {
            $package = $shard->package()->path();

            if (! $package->equals(Path::root())) {
                return CannotJudge::because(sprintf(self::PACKAGED, $package->value()));
            }

            $ordered = $ordered->with($shard->ordered($order));
        }

        return $ordered;
    }

    /** Each unit to run, in the package its tree is in, weighed by what the cost model expects of it. */
    private function workload(Units $units, Trees $trees, Ledgers $ledgers, FirstRun $firstRun): Workload
    {
        $costs = $ledgers->costs($this->setup->gate);
        $weighed = [];

        foreach ($units as $unit) {
            $tree = $trees->holding($unit->path());

            if ($tree instanceof Tree) {
                $estimated = $costs->estimated($this->adapters->costs, $unit, $firstRun);
                $weighed[] = Weighed::of($unit, $tree->package(), $estimated);
            }
        }

        return Workload::of(...$weighed);
    }
}
