<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Plan\OwnOnly;
use NightWorksIO\MutationGate\Core\Plan\Proving;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Records;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * The ledgers a run reads, the default branch's and its own scope's where it
 * has one of its own, and which of them it may write. A ledger the store
 * could not read is judged without, and a warning says why.
 */
final readonly class Ledgers
{
    private function __construct(
        private Scope|Detached $scope,
        private Scope $default,
        private Access $access,
        private Ledger $defaultBranch,
        private Ledger $own,
        private Warnings $unread,
    ) {
    }

    public static function read(ProofStore $store, Standing $standing, Writing $writing): self
    {
        $scope = $standing->runOn()->scope();
        $default = $standing->defaultBranch();
        $ownScope = $scope instanceof Scope && ! $scope->equals($default);

        $defaultBranch = $store->read($default);
        $own = $ownScope ? $store->read($scope) : Ledger::empty();

        return new self(
            $scope,
            $default,
            Access::of($scope, $default, $writing),
            $defaultBranch instanceof Unreadable ? $defaultBranch->ledger() : $defaultBranch,
            $own instanceof Unreadable ? $own->ledger() : $own,
            self::unreadIn($defaultBranch, $own),
        );
    }

    /** A warning for each ledger the store could not read, and why. */
    public function unread(): Warnings
    {
        return $this->unread;
    }

    public function access(): Access
    {
        return $this->access;
    }

    /** The default branch's ledger, which a run on it also writes. */
    public function defaultBranch(): Ledger
    {
        return $this->defaultBranch;
    }

    /** The run's own scope's ledger; empty on the default branch, and for a run with no scope. */
    public function own(): Ledger
    {
        return $this->own;
    }

    /** The ledger of the scope the run writes, as it was read: the default branch's on it, and its own elsewhere. */
    public function written(): Ledger
    {
        return $this->scope instanceof Scope && $this->scope->equals($this->default)
            ? $this->defaultBranch
            : $this->own;
    }

    /**
     * Which tests killed each mutant and the mutants of each function, as
     * every ledger read learned it: the run's own scope's where both know one.
     */
    public function killers(): KillHistory
    {
        return $this->own->killers()->and($this->defaultBranch->killers());
    }

    /** Each unit's newest result, in any ledger read. */
    public function newest(): NewestProofs
    {
        return $this->own->and($this->defaultBranch)->proofs()->newest();
    }

    /** Every record the ledgers read hold of the mutants an id or a prefix of one names, the newest first. */
    public function records(IdPrefix $sought): Records
    {
        $records = Records::none($sought)->in($this->default, $this->defaultBranch);

        return $this->scope instanceof Scope && ! $this->scope->equals($this->default)
            ? $records->in($this->scope, $this->own)
            : $records;
    }

    /** How long each unit took, as every ledger read learned it. */
    public function timings(): Timings
    {
        return $this->defaultBranch->timings()->and($this->own->timings());
    }

    /**
     * Of these units, those no ledger read timed with this gate, which only a
     * first run can measure: a timing another release of the gate measured
     * says nothing of what this one spends.
     */
    public function untimed(Units $units, Version $gate): Units
    {
        $timings = $this->timings()->measuredBy($gate->spelt());
        $untimed = [];

        foreach ($units as $unit) {
            if (! $timings->secondsFor($unit->path()) instanceof Seconds) {
                $untimed[] = $unit;
            }
        }

        return Units::of(...$untimed);
    }

    /**
     * What the cost model expects of this unit, from what every ledger read
     * learned of it with this gate, or, where none timed it so, what the plan
     * measured of its first run.
     */
    public function estimated(CostModel $costs, Unit $unit, FirstRun $firstRun, Version $gate): Estimated
    {
        return $costs->cost($unit, $this->timings()->measuredBy($gate->spelt()), $firstRun);
    }

    /**
     * Of these units, those a proof whose key still matches covers, and those
     * left to run, from the proofs that record what a run of this kind asks
     * for. A ledger with no proof at the base the keys are built on is not
     * looked in, since none of its proofs can match.
     */
    public function proving(Units $units, Keys $keys, Digest $base, MatrixKind $matrix): Proving
    {
        $defaultBranch = $this->defaultBranch->proofs()->recording($matrix);
        $own = $this->own->proofs()->recording($matrix);

        return Proving::of(
            $units,
            $keys,
            $defaultBranch->provesAt($base) ? $defaultBranch : Proofs::none(),
            $own->provesAt($base) ? $own : Proofs::none(),
        );
    }

    /**
     * Of these units, those the run considers, and those that carry the
     * newest proof of their path that records what a run of this kind asks
     * for, the units of these files carrying only their own scope's.
     */
    public function considering(Units $units, Reach $reach, MatrixKind $matrix, OwnOnly $ownOnly): Considering
    {
        return Considering::of(
            $units,
            $reach,
            $this->defaultBranch->proofs()->recording($matrix),
            $this->own->proofs()->recording($matrix),
            $ownOnly,
        );
    }

    /**
     * The newest commit of the run's own scope whose run judged every unit it
     * considered; none for a run with no scope.
     */
    public function lastRun(): LastRun|CannotTell
    {
        return match (true) {
            ! $this->scope instanceof Scope => CannotTell::because(
                'The run has no ref of its own, so no run of its own is recorded.',
            ),
            $this->readsOwnScope() => $this->own->runs()->lastRun(),
            default => $this->defaultBranch->runs()->lastRun(),
        };
    }

    /** Whether the run reads a scope of its own beside the default branch's: it runs off the default branch. */
    public function readsOwnScope(): bool
    {
        return $this->access->reads()->count() > 1;
    }

    /** The newest commit of the run's own scope whose verdict passed; none for a run with no scope. */
    public function lastPassed(): Passed|CannotTell
    {
        return match (true) {
            ! $this->scope instanceof Scope => CannotTell::because(
                'The run has no ref of its own, so no commit of its own has passed.',
            ),
            $this->readsOwnScope() => $this->own->runs()->passed(),
            default => $this->defaultBranch->runs()->passed(),
        };
    }

    private static function unreadIn(Ledger|Unreadable ...$read): Warnings
    {
        $warnings = Warnings::none();

        foreach ($read as $ledger) {
            $warnings = $ledger instanceof Unreadable ? $warnings->and($ledger->said()) : $warnings;
        }

        return $warnings;
    }
}
