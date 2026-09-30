<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a run saved against the gate itself run whole in one job: one setup,
 * one opening run and every unit's timing. Reach saved the units the change
 * did not reach, and proofs the reached units whose key matched. Sharding
 * cut the wait and cost setup and an opening run for each shard past the
 * first. The share of the full run that was measured rather than estimated
 * is said beside it (ADR-0017, decision 11).
 */
final readonly class Savings
{
    private function __construct(
        private Seconds $fullRun,
        private Percentage $measured,
        private Seconds $reach,
        private Seconds $proofs,
        private Seconds $waitSaved,
        private Seconds $shardingSetup,
        private bool $sharded,
    ) {
    }

    /** What an unsharded run saved. */
    public static function of(Seconds $fullRun, Percentage $measured, Seconds $reach, Seconds $proofs): self
    {
        return new self($fullRun, $measured, $reach, $proofs, Seconds::of(0.0), Seconds::of(0.0), sharded: false);
    }

    /** These savings, from a sharded run: the wait it cut, and the setup it cost. */
    public function sharded(Seconds $waitSaved, Seconds $setup): self
    {
        return clone($this, ['waitSaved' => $waitSaved, 'shardingSetup' => $setup, 'sharded' => true]);
    }

    public function fullRun(): Seconds
    {
        return $this->fullRun;
    }

    /** How much of the full run is measured rather than estimated. */
    public function measured(): Percentage
    {
        return $this->measured;
    }

    public function reach(): Seconds
    {
        return $this->reach;
    }

    public function proofs(): Seconds
    {
        return $this->proofs;
    }

    public function isSharded(): bool
    {
        return $this->sharded;
    }

    /** The full run less the run's critical path; nothing for an unsharded run. */
    public function waitSaved(): Seconds
    {
        return $this->waitSaved;
    }

    /** Setup and an opening run for each shard past the first; nothing for an unsharded run. */
    public function shardingSetup(): Seconds
    {
        return $this->shardingSetup;
    }
}
