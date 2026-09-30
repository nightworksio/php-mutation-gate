<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * One unit's mutants as its runner reported them, whether they come from this
 * run, a proof or a carried result, and which of them gave two answers.
 */
final readonly class UnitResult
{
    private function __construct(
        private Unit $unit,
        private Origin $origin,
        private Mutants $mutants,
        private MutantIds $flaky,
    ) {
    }

    public static function of(Unit $unit, Origin $origin, Mutants $mutants): self
    {
        return new self($unit, $origin, $mutants, MutantIds::none());
    }

    /** This result, with these of its mutants flaky: they survived once and were killed when run again. */
    public function withFlaky(MutantIds $flaky): self
    {
        return new self($this->unit, $this->origin, $this->mutants, $flaky);
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

    public function flaky(): MutantIds
    {
        return $this->flaky;
    }
}
