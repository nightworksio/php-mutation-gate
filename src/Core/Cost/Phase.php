<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * One timed stretch of a run, such as its plan or a shard's step: when it
 * began, as an instant to the second and how long after it, and how long it
 * took.
 */
final readonly class Phase
{
    private function __construct(private Instant $start, private Seconds $after, private Seconds $duration)
    {
    }

    public static function of(Instant $start, Seconds $duration): self
    {
        return new self($start, Seconds::of(0.0), $duration);
    }

    /** The instant it began at, to the second, before what {@see after()} adds. */
    public function start(): Instant
    {
        return $this->start;
    }

    /** How long after its instant it began. */
    public function after(): Seconds
    {
        return $this->after;
    }

    /** The same stretch, begun this much later. */
    public function later(Seconds $by): self
    {
        return new self($this->start, Seconds::of($this->after->seconds() + $by->seconds()), $this->duration);
    }

    public function duration(): Seconds
    {
        return $this->duration;
    }
}
