<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

/**
 * The helper answers nothing, and says why. The gate then works the answer
 * out in its own PHP, which is the definition, so a run never depends on the
 * helper (ADR-0029).
 */
final readonly class NotAccelerated
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
