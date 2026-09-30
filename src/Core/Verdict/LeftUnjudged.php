<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * The units a time budget ran out before (ADR-0008, decision 1), as the
 * verdict counts them. Each counts by its newest result in the ledgers, with
 * every mutant of it unjudged, so each counts as not killed and keeps its
 * tree's floor from rising. A unit no ledger holds a result of has no mutants
 * to count, and fails the verdict instead. None of them reaches the ledger.
 */
final readonly class LeftUnjudged
{
    private const string UNCOUNTED = <<<'SAID'
        %s is unjudged: the time budget ran out before this run mutated it.
        No ledger holds a result of it to count as not killed. More time judges it: %s
        SAID;

    private function __construct(private Units $units, private NewestProofs $newest)
    {
    }

    public static function of(Units $units, NewestProofs $newest): self
    {
        return new self($units, $newest);
    }

    /** Each unit a ledger holds a result of, by its newest one, every mutant of it unjudged. */
    public function results(): UnitResults
    {
        $results = UnitResults::none();

        foreach ($this->units as $unit) {
            $proof = $this->newest->of($unit->path());
            $results = $proof instanceof Proof
                ? $results->with(UnitResult::of($unit, Origin::Carried, $this->unjudged($proof)))
                : $results;
        }

        return $results;
    }

    /** Why each unit no ledger holds a result of fails the verdict. */
    public function failures(): Failures
    {
        $failures = Failures::none();

        foreach ($this->units as $unit) {
            $said = sprintf(self::UNCOUNTED, $unit->path()->value(), OutOfTime::MORE_TIME);
            $proved = $this->newest->of($unit->path()) instanceof Proof;
            $failures = $proved ? $failures : $failures->with(Failure::that($said));
        }

        return $failures;
    }

    /** Every mutant a proof holds, in full or as a kill, unjudged. */
    private function unjudged(Proof $proof): Mutants
    {
        $mutants = Mutants::none();

        foreach ($proof->reported() as $mutant) {
            $mutants = $mutants->with($mutant->unjudged(OutOfTime::BeforeMutating));
        }

        foreach ($proof->kills() as $kill) {
            $mutants = $mutants->with(Mutant::of(
                $kill->id(),
                '',
                $kill->location(),
                Mutation::of($kill->mutator(), MutatorFamily::Unknown, ''),
                MutantStatus::Unjudged,
                Unmeasured::duration(),
            )->unjudged(OutOfTime::BeforeMutating));
        }

        return $mutants;
    }
}
