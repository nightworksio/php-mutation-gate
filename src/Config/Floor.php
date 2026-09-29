<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

/** A floor, from 0 to 100, as written: `Floor::of(100)`. The validator checks its range. */
final readonly class Floor
{
    private function __construct(private int|float $percent)
    {
    }

    public static function of(int|float $percent): self
    {
        return new self($percent);
    }

    public function percent(): int|float
    {
        return $this->percent;
    }
}
