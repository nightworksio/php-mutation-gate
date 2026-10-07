<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The units a time budget ran out before (ADR-0008, decision 1), or a run
 * stopped before once it could not pass (decision 6), as the verdict counts
 * them. A unit whose newest result in the ledgers is of the source and
 * mutant set the code on disk makes counts by it: each of its mutants
 * stands, or is unjudged, as {@see Carrying} says, and an unjudged one
 * counts as not killed. Any other unit a budget left fails the verdict,
 * named with why and the command that judges it; one a doomed run stopped
 * before needs no failure of its own, since the survivor that stopped the
 * run fails the verdict. None of them reaches the ledger.
 */
final readonly class LeftUnjudged
{
    private function __construct(
        private Units $units,
        private NewestProofs $newest,
        private Carrying $carrying,
        private Doomed|Undoomed $doomed,
    ) {
    }

    /** These units, their newest results in the ledgers the run reads, and what judges what of those stands. */
    public static function of(Units $units, NewestProofs $newest, Carrying $carrying): self
    {
        return new self($units, $newest, $carrying, Undoomed::run());
    }

    /** The same, of units the run stopped before once this survivor made it certain to fail. */
    public static function stoppedBy(Doomed $doomed, Units $units, NewestProofs $newest, Carrying $carrying): self
    {
        return new self($units, $newest, $carrying, $doomed);
    }

    /** Each unit its newest result stands for, by that result, each mutant of it standing or unjudged. */
    public function results(): UnitResults
    {
        $results = UnitResults::none();

        foreach ($this->units as $unit) {
            $counted = $this->carrying->counted($this->newest->of($unit->path()));
            $carried = $counted instanceof Proof
                ? UnitResult::held(
                    $unit,
                    Origin::Carried,
                    $this->reported($counted),
                    $this->kills($counted),
                    $counted->run(),
                )->judgedBy($counted->judging())
                : $counted;
            $results = $carried instanceof UnitResult ? $results->with($carried) : $results;
        }

        return $results;
    }

    /** Why each unit a budget left, whose newest result cannot stand for it, fails the verdict. */
    public function failures(): Failures
    {
        $failures = Failures::none();

        foreach ($this->doomed instanceof Doomed ? [] : $this->units as $unit) {
            $counted = $this->carrying->counted($this->newest->of($unit->path()));
            $failures = $counted instanceof Uncounted
                ? $failures->with(Failure::that($counted->said($unit->path())))
                : $failures;
        }

        return $failures;
    }

    /** The mutants a result holds in full, each standing or unjudged. */
    private function reported(Proof $proof): Mutants
    {
        $mutants = [];

        foreach ($proof->reported() as $mutant) {
            $stands = $this->carrying->carry($proof, $mutant) === Carry::Stands;
            $mutants[] = $stands ? $mutant : $mutant->unjudged($this->left());
        }

        return Mutants::of(...$mutants);
    }

    /** The kills a result holds, each standing or unjudged. */
    private function kills(Proof $proof): ProvedKills
    {
        $kills = [];

        foreach ($proof->kills() as $kill) {
            $stands = $this->carrying->carry($proof, $kill) === Carry::Stands;
            $kills[] = $stands ? $kill : $kill->unjudged($this->left());
        }

        return ProvedKills::of(...$kills);
    }

    /** Why a mutant that does not stand is unjudged: the budget ran out before its unit, or the run stopped. */
    private function left(): OutOfTime|Reason
    {
        return $this->doomed instanceof Doomed ? $this->doomed->left() : OutOfTime::BeforeMutating;
    }
}
