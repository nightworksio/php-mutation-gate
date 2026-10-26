<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * What the replays of a run's narrowed kills left (see NarrowedKills): the
 * kills a failed assertion made, unjudged where their replay does not let
 * them stand, and the kills only tests that errored made, doubted there, so
 * they run again with every test file (ADR-0036).
 */
final readonly class ReplayedKills
{
    private function __construct(private Mutants $unjudged, private Mutants $doubted)
    {
    }

    public static function of(Mutants $unjudged, Mutants $doubted): self
    {
        return new self($unjudged, $doubted);
    }

    public static function none(): self
    {
        return new self(Mutants::none(), Mutants::none());
    }

    /** The kills a failed assertion made whose replay does not let them stand, each unjudged with why. */
    public function unjudged(): Mutants
    {
        return $this->unjudged;
    }

    /** The kills only tests that errored made whose replay does not let them stand, as they were. */
    public function doubted(): Mutants
    {
        return $this->doubted;
    }
}
