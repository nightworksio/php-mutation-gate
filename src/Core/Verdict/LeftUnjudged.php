<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The units a time budget ran out before (ADR-0008, decision 1), as the
 * verdict counts them. A unit whose newest result in the ledgers is of the
 * source and mutant set the code on disk makes counts by it: each of its
 * mutants stands, or is unjudged, as {@see Carrying} says, and an unjudged
 * one counts as not killed. Any other unit fails the verdict, named with why
 * and the command that judges it. None of them reaches the ledger.
 */
final readonly class LeftUnjudged
{
    private function __construct(private Units $units, private NewestProofs $newest, private Carrying $carrying)
    {
    }

    /** These units, their newest results in the ledgers the run reads, and what judges what of those stands. */
    public static function of(Units $units, NewestProofs $newest, Carrying $carrying): self
    {
        return new self($units, $newest, $carrying);
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
                )
                : $counted;
            $results = $carried instanceof UnitResult ? $results->with($carried) : $results;
        }

        return $results;
    }

    /** Why each unit its newest result cannot stand for fails the verdict. */
    public function failures(): Failures
    {
        $failures = Failures::none();

        foreach ($this->units as $unit) {
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
        $mutants = Mutants::none();

        foreach ($proof->reported() as $mutant) {
            $stands = $this->carrying->carry($proof, $mutant) === Carry::Stands;
            $mutants = $mutants->with($stands ? $mutant : $mutant->unjudged(OutOfTime::BeforeMutating));
        }

        return $mutants;
    }

    /** The kills a result holds, each standing or unjudged. */
    private function kills(Proof $proof): ProvedKills
    {
        $kills = [];

        foreach ($proof->kills() as $kill) {
            $stands = $this->carrying->carry($proof, $kill) === Carry::Stands;
            $kills[] = $stands ? $kill : $kill->unjudged(OutOfTime::BeforeMutating);
        }

        return ProvedKills::of(...$kills);
    }
}
