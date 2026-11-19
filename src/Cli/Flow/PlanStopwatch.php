<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Cost\PlanLap;
use NightWorksIO\MutationGate\Core\Cost\PlanLaps;
use NightWorksIO\MutationGate\Core\Cost\PlanStep;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Psr\Clock\ClockInterface;

/**
 * The plan's steps, one after another, each from where the one before it
 * ended to where it is marked as ended, on the flow's clock, so every second
 * of the plan is a step's (ADR-0220).
 *
 * @internal
 */
final class PlanStopwatch
{
    private PlanLaps $laps;

    private DateTimeImmutable $mark;

    private function __construct(private readonly ClockInterface $clock, private readonly DateTimeImmutable $began)
    {
        $this->laps = PlanLaps::none();
        $this->mark = $began;
    }

    /** A stopwatch of a plan that begins now. */
    public static function started(ClockInterface $clock): self
    {
        return new self($clock, $clock->now());
    }

    /** The stopwatch given, or one of a plan that begins now where none is. */
    public static function given(self|NotGiven $given, ClockInterface $clock): self
    {
        return $given instanceof self ? $given : self::started($clock);
    }

    /** A stopwatch of a plan that began then. */
    public static function since(ClockInterface $clock, DateTimeImmutable $began): self
    {
        return new self($clock, $began);
    }

    /** The step that began where the last one ended, ended now. */
    public function lap(PlanStep $step): void
    {
        $now = $this->clock->now();
        $this->laps = $this->laps->with(PlanLap::of(
            $step,
            Seconds::between($this->began, $this->mark),
            Seconds::between($this->mark, $now),
        ));
        $this->mark = $now;
    }

    /** Every step ended so far, in the order they began. */
    public function laps(): PlanLaps
    {
        return $this->laps;
    }
}
