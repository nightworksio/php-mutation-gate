<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What one route cost a run (ADR-0230): the mutants it brought to their
 * verdict, the wall seconds its jobs spent in it, and those seconds times
 * the cores of each job, which the runner minutes pay for.
 */
final readonly class RouteCost
{
    private function __construct(
        private Route $route,
        private int $mutants,
        private Seconds $seconds,
        private Seconds $coreSeconds,
    ) {
    }

    public static function of(Route $route, int $mutants, Seconds $seconds, Seconds $coreSeconds): self
    {
        return new self($route, $mutants, $seconds, $coreSeconds);
    }

    public function route(): Route
    {
        return $this->route;
    }

    public function mutants(): int
    {
        return $this->mutants;
    }

    /** The wall seconds the run's jobs spent in the route, each job's added. */
    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    /** The wall seconds each job spent in the route, times the cores of its machine, added. */
    public function coreSeconds(): Seconds
    {
        return $this->coreSeconds;
    }

    /** The core-seconds of each mutant the route brought to its verdict; none where it brought none. */
    public function perMutant(): Seconds|NotGiven
    {
        return $this->mutants > 0 ? Seconds::of($this->coreSeconds->seconds() / $this->mutants) : NotGiven::value();
    }
}
