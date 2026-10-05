<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;

/** A plan, and the line that says how its coverage map was measured, where one does. */
final readonly class PlanMade
{
    private function __construct(private Plan $plan, private string|NotGiven $coverage)
    {
    }

    public static function of(Plan $plan, string|NotGiven $coverage): self
    {
        return new self($plan, $coverage);
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
}
