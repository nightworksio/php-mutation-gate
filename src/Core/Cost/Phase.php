<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** One timed stretch of a run, such as its plan or a shard's opening run: when it began and how long it took. */
final readonly class Phase
{
    private function __construct(private Instant $start, private Seconds $duration)
    {
    }

    public static function of(Instant $start, Seconds $duration): self
    {
        return new self($start, $duration);
    }

    public function start(): Instant
    {
        return $this->start;
    }

    public function duration(): Seconds
    {
        return $this->duration;
    }
}
