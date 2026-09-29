<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a shard measured: the time it spent mutating, from the end of its
 * opening run to its last mutant, which runner spent it, and when.
 */
final readonly class Measurement
{
    private function __construct(private Seconds $spent, private string $runner, private Instant $at)
    {
    }

    public static function of(Seconds $spent, string $runner, Instant $at): self
    {
        return new self($spent, $runner, $at);
    }

    public function spent(): Seconds
    {
        return $this->spent;
    }

    public function runner(): string
    {
        return $this->runner;
    }

    public function at(): Instant
    {
        return $this->at;
    }
}
