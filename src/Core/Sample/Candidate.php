<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * A unit a sample may run (ADR-0020): how many mutants it is
 * estimated to hold, and the tally of its last result, where it has one.
 */
final readonly class Candidate
{
    private function __construct(private Unit $unit, private int $mutants, private Tally|NotGiven $last)
    {
    }

    public static function of(Unit $unit, int $mutants, Tally|NotGiven $last): self
    {
        return new self($unit, $mutants, $last);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    /** How many mutants it is estimated to hold. */
    public function mutants(): int
    {
        return $this->mutants;
    }

    /** The tally of its last result; not given where it has none. */
    public function last(): Tally|NotGiven
    {
        return $this->last;
    }
}
