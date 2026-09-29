<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/** A unit's result that is not kept as a proof, and why: it did not run to the end, or it has no key. */
final readonly class NotRecorded
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
