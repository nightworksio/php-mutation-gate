<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

use NightWorksIO\MutationGate\Core\Score\Floor;

/** Why a tree's floor went down on purpose, and the floor it went down from. */
final readonly class Lowered
{
    private function __construct(private Floor $from, private string $reason)
    {
    }

    public static function from(Floor $from, string $reason): self
    {
        return new self($from, $reason);
    }

    public function floor(): Floor
    {
        return $this->from;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
