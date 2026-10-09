<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * One unit's mutants as its runner reported them, whether they come from this
 * run, a proof or a carried result, the run whose proof a proved or carried
 * one came from, which of them gave two answers, and, for a held unit, the
 * holding tests that run it.
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
        private TestIds $judging,
        private MutantIds $carriedPruned,
    ) {
    }

    /** A unit's result as a run reported it: every mutant in full. */
    public static function of(Unit $unit, Origin $origin, Mutants $mutants): self
    {
        return new self(
            $unit,
            $origin,
            $mutants,
            ProvedKills::none(),
            MutantIds::none(),
            ThisRun::result(),
            TestIds::none(),
            MutantIds::none(),
        );
    }

    /**
     * A unit's result from a proof: these of its mutants reported in full and
     * of the kills a ledger proved, and the run the proof names (ADR-0015,
     * decision 8).
     */
    public static function held(Unit $unit, Origin $origin, Mutants $mutants, ProvedKills $kills, Run $run): self
    {
        return new self($unit, $origin, $mutants, $kills, MutantIds::none(), $run, TestIds::none(), MutantIds::none());
    }

    /** A unit's result as a proof holds it, every mutant, kill and judging test as the proof keeps it. */
    public static function fromProof(Unit $unit, Origin $origin, Proof $proof): self
    {
        return self::held($unit, $origin, $proof->reported(), $proof->kills(), $proof->run())
            ->judgedBy($proof->judging());
    }

    /** This result, with these of its mutants flaky: they survived once and were killed when run again. */
    public function withFlaky(MutantIds $flaky): self
    {
        return clone($this, ['flaky' => $flaky]);
    }

    /** This result, its unit held by tests of which these run it (ADR-0005, decision 10). */
    public function judgedBy(TestIds $judging): self
    {
        return clone($this, ['judging' => $judging]);
    }

    /**
     * This result of a run that left some mutators out of its unit, with the
     * last result's mutants and kills of those mutators carried in (ADR-0025,
     * decision 1).
     */
    public function carryingPruned(Mutants $mutants, ProvedKills $kills): self
    {
        $ids = [];

        foreach ($mutants as $mutant) {
            $ids[] = $mutant->id();
        }

        foreach ($kills as $kill) {
            $ids[] = $kill->id();
        }

        return clone($this, [
            'mutants' => Mutants::of(...$this->mutants, ...$mutants),
            'kills' => ProvedKills::of(...$this->kills, ...$kills),
            'carriedPruned' => MutantIds::of(...$ids),
        ]);
    }

    /** The mutants and kills it carries from the last result for a mutator the run left out; none where it ran all. */
    public function carriedPruned(): MutantIds
    {
        return $this->carriedPruned;
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

    /** The holding tests that run a held unit; none for a unit the whole suite judges, or where no run said. */
    public function judging(): TestIds
    {
        return $this->judging;
    }
}
