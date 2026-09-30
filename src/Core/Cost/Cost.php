<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a run cost: the wall and runner time the plan expected, the same two
 * as measured, and the cost-model time of every unit reach carried or a
 * proof covered, each priced where the team gives a rate (ADR-0016,
 * decisions 6 and 7).
 */
final readonly class Cost
{
    private function __construct(
        private RunTime $planned,
        private RunTime $measured,
        private Seconds $spared,
        private Seconds $setup,
        private Rate|Unpriced $price,
    ) {
    }

    /** Each job's setup, as `shards.setup` gives it, which an estimated run time counts. */
    public function setup(): Seconds
    {
        return $this->setup;
    }

    /** A cost with each job's setup taken as `shards.setup` says where the CI did not measure it. */
    public static function of(RunTime $planned, RunTime $measured, Seconds $spared, Seconds $setup): self
    {
        return new self($planned, $measured, $spared, $setup, Unpriced::time());
    }

    /** This cost, priced at the team's rate for a runner minute. */
    public function pricedAt(Rate $price): self
    {
        return clone($this, ['price' => $price]);
    }

    public function planned(): RunTime
    {
        return $this->planned;
    }

    public function measured(): RunTime
    {
        return $this->measured;
    }

    /** The cost-model time of what reach and proofs spared. */
    public function spared(): Seconds
    {
        return $this->spared;
    }

    public function price(): Rate|Unpriced
    {
        return $this->price;
    }

    /** Whether each job's setup is estimated from `shards.setup`, because the CI did not say. */
    public function isSetupEstimated(): bool
    {
        return ! $this->measured->isMeasured();
    }
}
