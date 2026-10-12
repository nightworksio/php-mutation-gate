<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

/**
 * An installed Infection release `infection:patch` does not patch, and why. It
 * runs unpatched, with its own limit for each mutant, which every run's report
 * warns of (ADR-0004): no failure, unlike a patch it cannot write.
 */
final readonly class UnsupportedRelease
{
    private function __construct(private string $why)
    {
    }

    public static function because(string $why): self
    {
        return new self($why);
    }

    public function why(): string
    {
        return $this->why;
    }
}
