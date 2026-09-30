<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/** One unit's mutants as its runner reported them, and whether they come from this run, a proof or a carried result. */
final readonly class UnitResult
{
    private function __construct(private Unit $unit, private Origin $origin, private Mutants $mutants)
    {
    }

    public static function of(Unit $unit, Origin $origin, Mutants $mutants): self
    {
        return new self($unit, $origin, $mutants);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function origin(): Origin
    {
        return $this->origin;
    }

    public function mutants(): Mutants
    {
        return $this->mutants;
    }
}
