<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;

/**
 * A mutant the gate's own engine made (ADR-0023 decision 8): its id, where it
 * stands, its mutator and diff, and the whole file with it in place, which
 * the override serves in the original's place.
 *
 * @internal the engine's own
 */
final readonly class MadeMutant
{
    private function __construct(
        private MutantId $id,
        private Location $location,
        private Mutation $mutation,
        private Contents $mutated,
    ) {
    }

    public static function of(MutantId $id, Location $location, Mutation $mutation, Contents $mutated): self
    {
        return new self($id, $location, $mutation, $mutated);
    }

    public function id(): MutantId
    {
        return $this->id;
    }

    public function location(): Location
    {
        return $this->location;
    }

    public function mutation(): Mutation
    {
        return $this->mutation;
    }

    /** The whole file with the mutant in place. */
    public function mutated(): Contents
    {
        return $this->mutated;
    }
}
