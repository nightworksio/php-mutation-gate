<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/** How many of a run's shards ran at once at most, each from when it began to when it ended. */
final readonly class ShardsAtOnce
{
    private function __construct(private int $count)
    {
    }

    public static function of(int $count): self
    {
        return new self($count);
    }

    public function count(): int
    {
        return $this->count;
    }
}
