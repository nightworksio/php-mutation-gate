<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

/**
 * Version control cannot say what was asked, and why. The gate reads it as
 * "reach everything", never as "nothing changed".
 */
final readonly class CannotTell
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
