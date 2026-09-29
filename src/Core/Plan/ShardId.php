<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

/** A shard's number in its plan, counted from 1. */
final readonly class ShardId
{
    private function __construct(private int $number) {}

    public static function of(int $number): self
    {
        return new self($number);
    }

    public function number(): int
    {
        return $this->number;
    }
}
