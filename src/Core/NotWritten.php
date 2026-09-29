<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/**
 * Something could not be written, and why. It changes no verdict: a report or
 * a ledger that fails to write costs a reader or a later run, never a judgement.
 */
final readonly class NotWritten
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
