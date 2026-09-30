<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What a plan considered beyond its shards, and why: the lines a change
 * added or modified, which the new-code floor judges, why it reached what it
 * did, the units a proof whose key still matches holds, and the units whose
 * newest result is carried because the change does not reach them.
 */
final readonly class Considered
{
    private function __construct(
        private Changes $changed,
        private Reasons $reach,
        private Units $proved,
        private Units $carried,
    ) {
    }

    /** A full run's: no change, no reason, nothing proved or carried. */
    public static function everything(): self
    {
        return new self(Changes::none(), Reasons::of(), Units::none(), Units::none());
    }

    /** This, for a change: the lines it added or modified in each source file, and why it reached what it did. */
    public function reaching(Changes $changed, Reasons $reach): self
    {
        return clone($this, ['changed' => $changed, 'reach' => $reach]);
    }

    /** This, taking the result of each of these units from the proof its key matches. */
    public function proving(Units $proved): self
    {
        return clone($this, ['proved' => $proved]);
    }

    /** This, carrying the newest result of each of these units, which the change does not reach. */
    public function carrying(Units $carried): self
    {
        return clone($this, ['carried' => $carried]);
    }

    /** The lines the change added or modified in each source file; none for a full run. */
    public function changed(): Changes
    {
        return $this->changed;
    }

    /** Why the change reached what it did; none for a full run. */
    public function reach(): Reasons
    {
        return $this->reach;
    }

    /** The units whose result a proof whose key still matches holds, so no shard runs them. */
    public function proved(): Units
    {
        return $this->proved;
    }

    /** The units whose newest result the verdict carries, because the change does not reach them. */
    public function carried(): Units
    {
        return $this->carried;
    }
}
