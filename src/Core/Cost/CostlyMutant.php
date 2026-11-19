<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A mutant among a run's costliest: its record, the seconds its runner gave it, and its route (ADR-0230). */
final readonly class CostlyMutant
{
    private function __construct(private Mutant $mutant, private Seconds $seconds, private Route|NotGiven $route)
    {
    }

    public static function of(Mutant $mutant, Seconds $seconds, Route|NotGiven $route): self
    {
        return new self($mutant, $seconds, $route);
    }

    public function mutant(): Mutant
    {
        return $this->mutant;
    }

    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    /** The route that brought it to its verdict; none where no route did. */
    public function route(): Route|NotGiven
    {
        return $this->route;
    }
}
