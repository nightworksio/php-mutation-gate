<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

/** A declared floor of 0, which is not mutated, and the reason it carries. */
final readonly class Exempt
{
    private function __construct(private string $reason)
    {
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
