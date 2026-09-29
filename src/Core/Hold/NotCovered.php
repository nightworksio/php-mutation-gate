<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * The tests that hold a unit miss lines of it the whole suite covers, so they
 * cannot judge it, and the unit fails with the sentence that lists them.
 */
final readonly class NotCovered
{
    private function __construct(private Unit $unit, private string $why)
    {
    }

    public static function because(Unit $unit, string $why): self
    {
        return new self($unit, $why);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function why(): string
    {
        return $this->why;
    }
}
