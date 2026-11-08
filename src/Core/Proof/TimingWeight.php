<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** The weight a unit's newest measurement takes in its smoothed timing: `timing.newest`. */
final readonly class TimingWeight
{
    private function __construct(private float $newest)
    {
    }

    public static function of(float $newest): self
    {
        return new self($newest);
    }

    /** What a measurement says, smoothed over what an earlier one said. */
    public function smoothed(Seconds $newest, Seconds $earlier): Seconds
    {
        return Seconds::of($this->newest * $newest->seconds() + (1 - $this->newest) * $earlier->seconds());
    }
}
