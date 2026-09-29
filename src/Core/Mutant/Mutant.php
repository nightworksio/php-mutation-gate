<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * One mutant as a runner reported it: where it is, what it changed, whether a
 * test caught it, and how long it ran where the runner says. The native id is
 * the runner's own, and means something only within the run that printed it.
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
    ) {}

    public static function of(
        MutantId $id,
        string $nativeId,
        Location $location,
        Mutation $mutation,
        MutantStatus $status,
        Seconds|Unmeasured $duration,
    ): self {
        return new self($id, $nativeId, $location, $mutation, $status, $duration);
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
}
