<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/**
 * The gate cannot judge honestly, and says why in the sentence the user reads.
 * A run that meets one stops with exit code 2.
 */
final readonly class CannotJudge
{
    private function __construct(private string $why) {}

    public static function because(string $why): self
    {
        return new self($why);
    }

    public function why(): string
    {
        return $this->why;
    }
}
