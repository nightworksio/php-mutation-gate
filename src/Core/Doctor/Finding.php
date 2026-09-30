<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/**
 * What one check found: how much it matters, what it is, why it matters, the
 * exact fix, and, for a slow finding the timings measured, the time at stake.
 */
final readonly class Finding
{
    private function __construct(
        private Slug $slug,
        private Severity $severity,
        private string $found,
        private string $why,
        private string $fix,
        private Seconds|Unmeasured $atStake,
    ) {
    }

    public static function of(Slug $slug, Severity $severity, string $found, string $why, string $fix): self
    {
        return new self($slug, $severity, $found, $why, $fix, Unmeasured::duration());
    }

    /** This finding, with the time a run spends on what it found. */
    public function costing(Seconds $seconds): self
    {
        return new self($this->slug, $this->severity, $this->found, $this->why, $this->fix, $seconds);
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function severity(): Severity
    {
        return $this->severity;
    }

    public function found(): string
    {
        return $this->found;
    }

    public function why(): string
    {
        return $this->why;
    }

    /** The command to run, or the lines to add. */
    public function fix(): string
    {
        return $this->fix;
    }

    public function atStake(): Seconds|Unmeasured
    {
        return $this->atStake;
    }
}
