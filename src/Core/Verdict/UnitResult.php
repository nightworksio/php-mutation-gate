<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * One unit's mutants as its runner reported them, whether they come from this
 * run, a proof or a carried result, the run whose proof a proved or carried
 * one came from, and which of them gave two answers.
 */
final readonly class UnitResult
{
    private function __construct(
        private Unit $unit,
        private Origin $origin,
        private Mutants $mutants,
        private ProvedKills $kills,
        private MutantIds $flaky,
        private Run|ThisRun $run,
    ) {
    }

    /** A unit's result as a run reported it: every mutant in full. */
    public static function of(Unit $unit, Origin $origin, Mutants $mutants): self
    {
        return new self($unit, $origin, $mutants, ProvedKills::none(), MutantIds::none(), ThisRun::result());
    }

    /**
     * A unit's result from a proof: these of its mutants reported in full and
     * of the kills a ledger proved, and the run the proof names (ADR-0015,
     * decision 8).
     */
    public static function held(Unit $unit, Origin $origin, Mutants $mutants, ProvedKills $kills, Run $run): self
    {
        return new self($unit, $origin, $mutants, $kills, MutantIds::none(), $run);
    }

    /** A unit's result as a proof holds it, every mutant and kill as the proof keeps it. */
    public static function fromProof(Unit $unit, Origin $origin, Proof $proof): self
    {
        return self::held($unit, $origin, $proof->reported(), $proof->kills(), $proof->run());
    }

    /** This result, with these of its mutants flaky: they survived once and were killed when run again. */
    public function withFlaky(MutantIds $flaky): self
    {
        return clone($this, ['flaky' => $flaky]);
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

    /** The run whose proof the result came from; this run for a unit a run just mutated. */
    public function run(): Run|ThisRun
    {
        return $this->run;
    }
}
