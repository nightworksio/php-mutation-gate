<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/** A unit and the test files the runner says can judge it, or why it cannot say. */
final readonly class Judging
{
    private function __construct(private Unit $unit, private Paths|CannotJudge $judges)
    {
    }

    public static function of(Unit $unit, Paths|CannotJudge $judges): self
    {
        return new self($unit, $judges);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function judges(): Paths|CannotJudge
    {
        return $this->judges;
    }
}
