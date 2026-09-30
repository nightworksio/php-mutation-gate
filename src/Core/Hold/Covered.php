<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use NightWorksIO\MutationGate\Core\Unit\Unit;

/** The tests that hold a unit cover every line of it the whole suite covers, so they may judge it. */
final readonly class Covered
{
    private function __construct(private Unit $unit)
    {
    }

    public static function by(Unit $unit): self
    {
        return new self($unit);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }
}
