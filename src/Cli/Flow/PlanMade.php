<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * A plan, the line that says how its coverage map was measured, where one
 * does, and a warning for each ledger it was made without.
 *
 * @internal
 */
final readonly class PlanMade
{
    private function __construct(private Plan $plan, private string|NotGiven $coverage, private Warnings $unread)
    {
    }

    public static function of(Plan $plan, string|NotGiven $coverage): self
    {
        return new self($plan, $coverage, Warnings::none());
    }

    /** This plan, made without the ledgers these warnings say the store could not read. */
    public function without(Warnings $unread): self
    {
        return new self($this->plan, $this->coverage, $unread);
    }

    public function plan(): Plan
    {
        return $this->plan;
    }

    /** How the plan's coverage map was measured: against a kept map, or why every test was measured. */
    public function coverage(): string|NotGiven
    {
        return $this->coverage;
    }

    /** A warning for each ledger the plan was made without, and why the store could not read it. */
    public function unread(): Warnings
    {
        return $this->unread;
    }
}
