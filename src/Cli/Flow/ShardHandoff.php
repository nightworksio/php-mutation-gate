<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\PlanStep;
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
 * history it hands them (see Handoff), the whole map written in a child
 * beside the readying (ADR-0169), the map every shard opens on where
 * the runner readies it (ADR-0163), the static analyser warmed up over the
 * originals where they check mutants with it (ADR-0164), begun beside the
 * maps where the plan did not begin it earlier (ADR-0180), and a mutant's
 * start-up, measured once the analyser is done, so it runs alone
 * (ADR-0165).
 *
 * @internal
 */
final readonly class ShardHandoff
{
    private PlanStopwatch $stopwatch;

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private Setup $setup,
        PlanStopwatch|NotGiven $stopwatch = new NotGiven(),
    ) {
        $this->stopwatch = PlanStopwatch::given($stopwatch, $setup->clock);
    }

    /** What the plan readied for its shards, or why it could not hand them their maps. */
    public function of(
        Plan $plan,
        CoverageMap $map,
        KillHistory $history,
        MeasuredAt|Unplaced $at,
        EntryKeys|NotGiven $keys,
        Subsumption|NotGiven $held,
        WarmingUp|NotGiven $warming,
    ): Written|Missing|CannotJudge {
        $warming = $warming instanceof WarmingUp ? $warming : $this->warmingBegun();
        $level = $this->settings->proofs()->coverageCompressionLevel();
        $whole = HandedWhole::begun($this->adapters->project, $map, $at, $keys, $level);
        $this->stopwatch->lap(PlanStep::WholeMapBegun);
        $opened = $this->adapters->runner->behaviour()->opening()
            ->readied($map, $plan->shards()->paths(), $this->adapters->suite->testCases());
        $this->stopwatch->lap(PlanStep::Opening);
        $handed = new Handoff($this->adapters->project, Handoff::limits(), $this->adapters->maps, $level)
            ->write($plan, $map, $history, $at, $keys, $held, $opened, $whole);
        $this->stopwatch->lap(PlanStep::Handoff);

        $warmed = $warming->leftFor($plan);
        $this->stopwatch->lap(PlanStep::WarmUp);
        $warmed = $handed instanceof CannotJudge ? $handed : $warmed;
        $started = $warmed instanceof CannotJudge
            ? $warmed
            : new PlannedStartUp($this->adapters, $this->settings)->left($plan, $map);
        $this->stopwatch->lap(PlanStep::StartUp);

        return $started;
    }

    /** The analyser's warm-up, begun beside the maps where the plan did not begin it earlier (ADR-0180). */
    private function warmingBegun(): WarmingUp
    {
        $warming = new PlannedWarmUp($this->adapters, $this->settings, $this->setup->clock)->begun();
        $this->stopwatch->lap(PlanStep::WarmUpBegun);

        return $warming;
    }
}
