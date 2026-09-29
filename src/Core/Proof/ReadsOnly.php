<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/** A run that writes no ledger, and why. */
final readonly class ReadsOnly
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
