<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function max;

/**
 * The price of a share of left mutants as a cut fills it (ADR-0195): the
 * seconds of its test files, each once, the longest of them, its forks at
 * the ledger's killers and its mutants' covering tests, and the wall
 * seconds a job's split streams take over them.
 *
 * @internal
 */
final readonly class LeftShare
{
    private function __construct(
        private StreamWall $wall,
        private float $seconds,
        private float $longest,
        private float $killers,
        private float $covering,
        private float $alone = 0.0,
    ) {
    }

    /** The price of a share of this group alone, by this wall. */
    public static function of(LeftGroup $group, StreamWall $wall): self
    {
        return new self(
            $wall,
            $group->seconds(),
            $group->longest(),
            $group->killers(),
            $group->covering(),
            $group->alone(),
        );
    }

    public function first(): float
    {
        return $this->wall->first($this->seconds, $this->longest, $this->killers);
    }

    public function second(): float
    {
        return $this->wall->second($this->covering);
    }

    public function wall(): float
    {
        return $this->first() + $this->second() + $this->wall->alone($this->alone);
    }

    /** The price of this share with a group joined to it, whose test files add these seconds. */
    public function with(LeftGroup $group, float $added): self
    {
        return new self(
            $this->wall,
            $this->seconds + $added,
            max($this->longest, $group->longest()),
            $this->killers + $group->killers(),
            $this->covering + $group->covering(),
            $this->alone + $group->alone(),
        );
    }
}
