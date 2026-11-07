<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function count;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Cost\Cost;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What a planned run saved against the gate itself run whole in one job
 * (ADR-0017): one setup, one opening run and every unit's timing, which reach
 * saved of the units the change did not reach, and proofs of the reached units
 * whose key matched. A unit the plan ran counts what the plan expected of it,
 * and is measured where a ledger timed it. A unit no ledger timed and no shard
 * ran counts nothing, and lowers the share said to be measured. A run with no
 * timings has no history to say anything from.
 */
final readonly class PlanSavings
{
    private function __construct(private Plan $plan, private Timings $timings, private Seconds $setup)
    {
    }

    /** The savings of a plan with these timings, whose every job first spends this long on its setup. */
    public static function of(Plan $plan, Timings $timings, Seconds $setup): self
    {
        return new self($plan, $timings, $setup);
    }

    /** What the run saved, having spent this. */
    public function saved(RunTime $spent): Savings|NoHistory
    {
        return count($this->timings) === 0 ? NoHistory::yet() : $this->savedBy($spent);
    }

    /** What the run cost against what its plan expected, and what reach and proofs spared. */
    public function cost(RunTime $spent): Cost
    {
        return Cost::of(
            PlanEstimates::of($this->plan, $this->setup)->runTime(),
            $spent,
            Seconds::of($this->spared()),
            $this->setup,
        );
    }

    private function savedBy(RunTime $spent): Savings
    {
        $considered = $this->plan->considered();
        $reach = $this->timedOf($considered->carried());
        $proofs = $this->timedOf($considered->proved());
        $opening = $this->opening();
        $ran = 0.0;
        $timedRun = 0.0;
        $jobs = 0;

        foreach ($this->plan as $shard) {
            $ran += $shard->cost()->seconds();
            $timedRun += min($this->timedOf($shard->units()), $shard->cost()->seconds());
            $jobs += $shard->isEmpty() ? 0 : 1;
        }

        $units = $reach + $proofs + $ran;
        $full = $this->setup->seconds() + $opening + $units;
        $measured = $units > 0.0
            ? Percentage::share(Seconds::of($reach + $proofs + $timedRun)->microseconds(), Seconds::of($units)->microseconds())
            : Percentage::none();
        $savings = Savings::of(
            Seconds::of($full),
            $measured instanceof Percentage ? $measured : Percentage::none(),
            Seconds::of($reach),
            Seconds::of($proofs),
        );

        return $jobs > 1 ? $savings->sharded(
            Seconds::of(max(0.0, $full - $spent->wall()->seconds())),
            Seconds::of(($this->setup->seconds() + $opening) * ($jobs - 1)),
        ) : $savings;
    }

    /** What the units reach and proofs left out would have taken. */
    private function spared(): float
    {
        $considered = $this->plan->considered();

        return $this->timedOf($considered->carried()) + $this->timedOf($considered->proved());
    }

    /** The timings of those of these units a ledger timed, together. */
    private function timedOf(Units $units): float
    {
        $seconds = 0.0;

        foreach ($units as $unit) {
            $timed = $this->timings->secondsFor($unit->path());
            $seconds += $timed instanceof Seconds ? $timed->seconds() : 0.0;
        }

        return $seconds;
    }

    /** One opening run: the longest any shard's is expected to be. */
    private function opening(): float
    {
        $opening = 0.0;

        foreach ($this->plan as $shard) {
            $opening = max($opening, $shard->estimate()->openingRun()->seconds());
        }

        return $opening;
    }
}
