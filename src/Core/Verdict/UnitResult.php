<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
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
        private ProvedKills $kills,
        private MutantIds $flaky,
    ) {
    }

    /** A unit's result as a run reported it: every mutant in full. */
    public static function of(Unit $unit, Origin $origin, Mutants $mutants): self
    {
        return new self($unit, $origin, $mutants, ProvedKills::none(), MutantIds::none());
    }

    /** A unit's result as a proof holds it: its mutants reported in full, and the kills a ledger proved. */
    public static function held(Unit $unit, Origin $origin, Mutants $mutants, ProvedKills $kills): self
    {
        return new self($unit, $origin, $mutants, $kills, MutantIds::none());
    }

    /** This result, with these of its mutants flaky: they survived once and were killed when run again. */
    public function withFlaky(MutantIds $flaky): self
    {
        return new self($this->unit, $this->origin, $this->mutants, $this->kills, $flaky);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function origin(): Origin
    {
        return $this->origin;
    }

    /** The mutants reported in full. */
    public function mutants(): Mutants
    {
        return $this->mutants;
    }

    /** The kills a ledger proved; none for a unit a run just mutated. */
    public function kills(): ProvedKills
    {
        return $this->kills;
    }

    public function flaky(): MutantIds
    {
        return $this->flaky;
    }
}
