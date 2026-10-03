<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * The tests that hold a unit cover every line of it the whole suite covers,
 * so they may judge it; and which of them run any of it, as their run alone
 * under coverage found.
 */
final readonly class Covered
{
    private function __construct(private Unit $unit, private TestIds $tests)
    {
    }

    public static function by(Unit $unit, TestIds $tests): self
    {
        return new self($unit, $tests);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    /** The holding tests that run any line of the unit: those that judge its mutants. */
    public function tests(): TestIds
    {
        return $this->tests;
    }
}
