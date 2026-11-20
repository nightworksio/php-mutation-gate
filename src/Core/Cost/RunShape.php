<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/** How a run was laid out (ADR-0230): the jobs it took, its rounds of shards, and the mutants it left unjudged. */
final readonly class RunShape
{
    private function __construct(private int $jobs, private int $rounds, private int $unjudged)
    {
    }

    public static function of(int $jobs, int $rounds, int $unjudged): self
    {
        return new self($jobs, $rounds, $unjudged);
    }

    public function jobs(): int
    {
        return $this->jobs;
    }

    public function rounds(): int
    {
        return $this->rounds;
    }

    public function unjudged(): int
    {
        return $this->unjudged;
    }
}
