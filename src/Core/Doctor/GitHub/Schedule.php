<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\GitHub;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * How the workflows that run the gate run on a schedule, as GitHub tells
 * it: which of them GitHub knows, the ones it disabled for inactivity, and
 * the last run any of them made on a schedule, if any did.
 */
final readonly class Schedule
{
    private function __construct(private Paths $workflows, private Paths $disabled, private Instant|NotGiven $lastRun)
    {
    }

    public static function of(Paths $workflows, Paths $disabled, Instant|NotGiven $lastRun): self
    {
        return new self($workflows, $disabled, $lastRun);
    }

    /** The workflows that run the gate, by their files, as GitHub knows them. */
    public function workflows(): Paths
    {
        return $this->workflows;
    }

    /** The workflows GitHub disabled after 60 days without activity, whose schedules no longer run. */
    public function disabled(): Paths
    {
        return $this->disabled;
    }

    public function lastRun(): Instant|NotGiven
    {
        return $this->lastRun;
    }
}
