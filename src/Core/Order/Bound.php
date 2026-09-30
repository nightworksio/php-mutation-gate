<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

/** How many of something a kill history keeps at most, the ones that learned a killer most recently. */
final readonly class Bound
{
    /** @param positive-int $count */
    private function __construct(private int $count)
    {
    }

    /** @param positive-int $count */
    public static function atMost(int $count): self
    {
        return new self($count);
    }

    /** @return positive-int */
    public function count(): int
    {
        return $this->count;
    }
}
