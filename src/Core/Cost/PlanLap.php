<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** One step of the plan: how long after the plan began it started, and how long it took (ADR-0220). */
final readonly class PlanLap
{
    private function __construct(private PlanStep $step, private Seconds $since, private Seconds $took)
    {
    }

    public static function of(PlanStep $step, Seconds $since, Seconds $took): self
    {
        return new self($step, $since, $took);
    }

    public function step(): PlanStep
    {
        return $this->step;
    }

    /** How long after the plan began the step started. */
    public function since(): Seconds
    {
        return $this->since;
    }

    public function took(): Seconds
    {
        return $this->took;
    }
}
