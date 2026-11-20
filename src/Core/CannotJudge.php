<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/**
 * The gate cannot judge honestly, and says why in the sentence the user reads.
 * A run that meets one stops with exit code 2. One the deadline caused says
 * so, for a step that can leave what it judges to a later job (ADR-0300).
 */
final readonly class CannotJudge
{
    private function __construct(private string $why, private bool $atTheDeadline = false)
    {
    }

    public static function because(string $why): self
    {
        return new self($why);
    }

    /** Why, where the deadline stopped the run before it could say anything. */
    public static function atTheDeadline(string $why): self
    {
        return new self($why, atTheDeadline: true);
    }

    /** Whether the deadline, and nothing else, is why. */
    public function isAtTheDeadline(): bool
    {
        return $this->atTheDeadline;
    }

    public function why(): string
    {
        return $this->why;
    }
}
