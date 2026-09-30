<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * One mutant as a runner reported it: where it is, what it changed, whether a
 * test caught it, how long it ran where the runner says, and, for a mutant
 * that timed out, the seconds the runner allowed it. The native id is the
 * runner's own, and means something only within the run that printed it. A
 * mutant the runner left unjudged can say why, and a killed one names the
 * tests that killed it where the runner does.
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
        private Seconds|Unmeasured $limit,
        private Reason|Unreported $reason,
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
            Unreported::reason(),
            TestIds::none(),
        );
    }

    /** This mutant, with the seconds its runner allowed it. */
    public function withLimit(Seconds $limit): self
    {
        return clone($this, ['limit' => $limit]);
    }

    /** This mutant, saying why it has the status it has. */
    public function because(Reason $reason): self
    {
        return clone($this, ['reason' => $reason]);
    }

    /**
     * This mutant, killed by these tests: the first that failed on it, or
     * every one that failed under a full kill matrix.
     */
    public function killedBy(TestIds $tests): self
    {
        return clone($this, ['killers' => $tests]);
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

    public function status(): MutantStatus
    {
        return $this->status;
    }

    public function duration(): Seconds|Unmeasured
    {
        return $this->duration;
    }

    /** The seconds the runner allowed it, where it says. */
    public function limit(): Seconds|Unmeasured
    {
        return $this->limit;
    }

    public function reason(): Reason|Unreported
    {
        return $this->reason;
    }

    /** The tests that killed it; none where no test is known to have, as for a kill by a timeout. */
    public function killers(): TestIds
    {
        return $this->killers;
    }
}
