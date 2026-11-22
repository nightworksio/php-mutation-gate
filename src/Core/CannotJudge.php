<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/**
 * The gate cannot judge honestly, and says why in the sentence the user reads.
 * A run that meets one stops with exit code 2. One the deadline caused says
 * so, for a step that can leave what it judges to a later job (ADR-0300); one
 * a runner that died while it judged its mutants caused says so, for a
 * verdict that leaves those mutants unjudged (ADR-0330).
 */
final readonly class CannotJudge
{
    private function __construct(
        private string $why,
        private bool $atTheDeadline = false,
        private bool $crashed = false,
    ) {
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

    /** Why, where the runner died after it had made its mutants and before it had judged them all. */
    public static function crashed(string $why): self
    {
        return new self($why, crashed: true);
    }

    /** Why, as a result file told it: crashed or not. */
    public static function told(string $why, bool $crashed): self
    {
        return new self($why, crashed: $crashed);
    }

    /** Whether the deadline, and nothing else, is why. */
    public function isAtTheDeadline(): bool
    {
        return $this->atTheDeadline;
    }

    /** Whether the runner died while it judged its mutants. */
    public function isCrashed(): bool
    {
        return $this->crashed;
    }

    public function why(): string
    {
        return $this->why;
    }
}
