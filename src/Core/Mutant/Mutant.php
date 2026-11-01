<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * One mutant as a runner reported it: where it is, what it changed, whether a
 * test caught it, how long it ran where the runner says, and, for a mutant
 * a limit stopped, the limit and what the unmutated code needs of it: for
 * one that timed out, the seconds the runner allowed it and the seconds its
 * judging tests take on their own, which timeout triage compares; for one
 * that ran out of the memory cap, the cap and the most memory the unmutated
 * suite's largest process held, which memory triage compares. The native id
 * is the runner's own, and means something only within the run that printed
 * it. A mutant the runner left unjudged can say why, and a killed one names
 * the tests that killed it where the runner does. One a static analyser
 * killed has the rejection that killed it as its reason.
 */
final readonly class Mutant
{
    private function __construct(
        private MutantId $id,
        private string $nativeId,
        private Location $location,
        private Mutation $mutation,
        private MutantStatus $status,
        private Seconds|Unmeasured $duration,
        private Seconds|MemoryCap|Unmeasured $limit,
        private Seconds|MemoryCap|Unmeasured $unmutatedNeed,
        private Reason|Rejection|Unreported $reason,
        private TestIds $killers,
    ) {
    }

    public static function of(
        MutantId $id,
        string $nativeId,
        Location $location,
        Mutation $mutation,
        MutantStatus $status,
        Seconds|Unmeasured $duration,
    ): self {
        return new self(
            $id,
            $nativeId,
            $location,
            $mutation,
            $status,
            $duration,
            Unmeasured::duration(),
            Unmeasured::duration(),
            Unreported::reason(),
            TestIds::none(),
        );
    }

    /**
     * This mutant under another of the gate's ids, as a runner hands a mutant
     * found again back under the id its first run gave it.
     */
    public function identifiedAs(MutantId $id): self
    {
        return new self(
            $id,
            $this->nativeId,
            $this->location,
            $this->mutation,
            $this->status,
            $this->duration,
            $this->limit,
            $this->unmutatedNeed,
            $this->reason,
            $this->killers,
        );
    }

    /** This mutant, with the limit that stopped it: the seconds its runner allowed it, or the memory cap. */
    public function withLimit(Seconds|MemoryCap $limit): self
    {
        return new self(
            $this->id,
            $this->nativeId,
            $this->location,
            $this->mutation,
            $this->status,
            $this->duration,
            $limit,
            $this->unmutatedNeed,
            $this->reason,
            $this->killers,
        );
    }

    /**
     * This mutant, whose unmutated code needs this much of its limit: the
     * seconds its judging tests took unmutated under the same limit, as its
     * control or its trial ran them, or the most memory the suite's largest
     * process held.
     */
    public function withUnmutatedNeed(Seconds|MemoryCap $need): self
    {
        return new self(
            $this->id,
            $this->nativeId,
            $this->location,
            $this->mutation,
            $this->status,
            $this->duration,
            $this->limit,
            $need,
            $this->reason,
            $this->killers,
        );
    }

    /** This mutant, saying why it has the status it has. */
    public function because(Reason $reason): self
    {
        return new self(
            $this->id,
            $this->nativeId,
            $this->location,
            $this->mutation,
            $this->status,
            $this->duration,
            $this->limit,
            $this->unmutatedNeed,
            $reason,
            $this->killers,
        );
    }

    /**
     * This mutant, left without a result: by a time budget that ran out
     * before this, for this reason, as a run that stopped once it could not
     * pass leaves it, or because its tests fail without it, which names the
     * tests that fail (ADR-0008).
     */
    public function unjudged(OutOfTime|Reason|UnmutatedFailure $why): self
    {
        return match (true) {
            $why instanceof UnmutatedFailure => new self(
                $this->id,
                $this->nativeId,
                $this->location,
                $this->mutation,
                MutantStatus::FailsUnmutated,
                $this->duration,
                $this->limit,
                $this->unmutatedNeed,
                $why->why(),
                $why->failing(),
            ),
            default => new self(
                $this->id,
                $this->nativeId,
                $this->location,
                $this->mutation,
                MutantStatus::Unjudged,
                $this->duration,
                $this->limit,
                $this->unmutatedNeed,
                $why instanceof OutOfTime ? $why->reason() : $why,
                $this->killers,
            ),
        };
    }

    /**
     * This mutant, killed by a static analyser that rejected it: so by no
     * test anyone knows, and for no reason but the rejection.
     */
    public function rejected(Rejection $rejection): self
    {
        return new self(
            $this->id,
            $this->nativeId,
            $this->location,
            $this->mutation,
            MutantStatus::KilledByStaticAnalysis,
            $this->duration,
            $this->limit,
            $this->unmutatedNeed,
            $rejection,
            TestIds::none(),
        );
    }

    /**
     * This mutant, killed by these tests: the first that failed on it, or
     * every one that failed under a full kill matrix.
     */
    public function killedBy(TestIds $tests): self
    {
        return new self(
            $this->id,
            $this->nativeId,
            $this->location,
            $this->mutation,
            $this->status,
            $this->duration,
            $this->limit,
            $this->unmutatedNeed,
            $this->reason,
            $tests,
        );
    }

    public function id(): MutantId
    {
        return $this->id;
    }

    public function nativeId(): string
    {
        return $this->nativeId;
    }

    public function location(): Location
    {
        return $this->location;
    }

    public function mutation(): Mutation
    {
        return $this->mutation;
    }

    /** The full name of its mutator, which a kill a ledger proved names too. */
    public function mutator(): string
    {
        return $this->mutation->mutator();
    }

    public function status(): MutantStatus
    {
        return $this->status;
    }

    public function duration(): Seconds|Unmeasured
    {
        return $this->duration;
    }

    /** The limit that stopped it, where it says: the seconds the runner allowed it, or the memory cap. */
    public function limit(): Seconds|MemoryCap|Unmeasured
    {
        return $this->limit;
    }

    /** What its unmutated code needs of its limit, where that was measured. */
    public function unmutatedNeed(): Seconds|MemoryCap|Unmeasured
    {
        return $this->unmutatedNeed;
    }

    /** Why it stands as it does: the runner's reason, or the rejection of the analyser that killed it. */
    public function reason(): Reason|Rejection|Unreported
    {
        return $this->reason;
    }

    /**
     * The tests that killed it, or, where its tests fail without it, those
     * that fail; none where no test is known to have, as for a kill by a
     * timeout.
     */
    public function killers(): TestIds
    {
        return $this->killers;
    }
}
