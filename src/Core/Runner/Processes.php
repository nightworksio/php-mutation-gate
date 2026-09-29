<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** How many processes a runner may run side by side. */
final readonly class Processes
{
    private function __construct(private int $count) {}

    public static function of(int $count): self
    {
        return new self($count);
    }

    public function count(): int
    {
        return $this->count;
    }
}
