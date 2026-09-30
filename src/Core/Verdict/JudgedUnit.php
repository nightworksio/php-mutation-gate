<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Unit\Unit;

/** One unit of a judged tree, and whether its result was run, proved or carried. */
final readonly class JudgedUnit
{
    private function __construct(private Unit $unit, private Origin $origin)
    {
    }

    public static function of(Unit $unit, Origin $origin): self
    {
        return new self($unit, $origin);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function origin(): Origin
    {
        return $this->origin;
    }
}
