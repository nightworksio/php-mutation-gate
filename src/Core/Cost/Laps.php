<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use Closure;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The steps of one runner's run, each timed from when the run began, on a
 * clock the runner reads (ADR-0016, decision 19).
 */
final readonly class Laps
{
    /** @param Closure(): Seconds $clock the seconds the runner's clock reads, from any start */
    private function __construct(private Closure $clock, private Seconds $started)
    {
    }

    /**
     * Laps of a run that begins now.
     *
     * @param Closure(): Seconds $clock the seconds the runner's clock reads, from any start
     */
    public static function from(Closure $clock): self
    {
        return new self($clock, $clock());
    }

    /**
     * Laps of a run that began when the clock read this.
     *
     * @param Closure(): Seconds $clock the seconds the runner's clock reads, from any start
     */
    public static function since(Closure $clock, Seconds $started): self
    {
        return new self($clock, $started);
    }

    /**
     * Laps of a run that begins now, on a clock that reads nanoseconds.
     *
     * @param Closure(): int $nanoseconds the nanoseconds the runner's clock reads, from any start
     */
    public static function fromNanoseconds(Closure $nanoseconds): self
    {
        return self::from(static fn(): Seconds => Seconds::of($nanoseconds() / Seconds::NANOSECONDS));
    }

    /** When a step begins, on the runner's clock. */
    public function now(): Seconds
    {
        return ($this->clock)();
    }

    /** A step that began then and ends now, having handled this many. */
    public function lap(Step $step, Seconds $from, int $count = 1): StepTime
    {
        return StepTime::of(
            $step,
            Seconds::of($from->seconds() - $this->started->seconds()),
            Seconds::of(($this->clock)()->seconds() - $from->seconds()),
            $count,
        );
    }
}
