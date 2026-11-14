<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * What a mutant's run is allowed to start in (ADR-0008): `timeouts.startUpMultiple`
 * times how long a run of no test took to start on this machine, where the
 * run measured it, and `timeouts.startUp` where nothing did.
 */
final readonly class StartUpAllowance
{
    private function __construct(
        private Seconds|Unmeasured $measured,
        private float $unmeasured,
        private float $multiple,
    ) {
    }

    /** This many seconds where nothing measured the start-up, and this many times it where something did. */
    public static function of(float $unmeasured = MutantLimit::START_UP, float $multiple = MutantLimit::FACTOR): self
    {
        return new self(Unmeasured::duration(), $unmeasured, $multiple);
    }

    /** This allowance, a run of no test having taken this long to start, or nothing having measured one. */
    public function measuredAs(Seconds|Unmeasured $measured): self
    {
        return new self($measured, $this->unmeasured, $this->multiple);
    }

    /** How long a run of no test took to start on this machine, or that nothing measured it. */
    public function measured(): Seconds|Unmeasured
    {
        return $this->measured;
    }

    /** What a run is allowed to start in where nothing measured its start-up: `timeouts.startUp`. */
    public function unmeasured(): Seconds
    {
        return Seconds::of($this->unmeasured);
    }

    /** `timeouts.startUpMultiple`: how many times a run of no test's measured start-up a mutant's run allows. */
    public function multiple(): float
    {
        return $this->multiple;
    }

    /** What a run is allowed to start in, in seconds. */
    public function allowed(): float
    {
        return $this->measured instanceof Seconds ? $this->multiple * $this->measured->seconds() : $this->unmeasured;
    }
}
