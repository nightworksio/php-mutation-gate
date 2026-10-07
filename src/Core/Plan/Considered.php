<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What a plan considered beyond its shards, and why: the lines a change
 * added or modified, which the new-code floor judges, those of them no test
 * runs, why it reached what it did, the units a proof whose key still
 * matches holds, the units whose newest result is carried because the
 * change does not reach them, and those of them that carry only the run's
 * own scope's newest result, since only it judged them as they are
 * (ADR-0005, decision 2).
 */
final readonly class Considered
{
    private function __construct(
        private Changes $changed,
        private Reasons $reach,
        private Units $proved,
        private Units $carried,
        private Changes $untested,
        private Units $carriedOwn,
    ) {
    }

    /** A full run's: no change, no reason, nothing proved or carried. */
    public static function everything(): self
    {
        return new self(Changes::none(), Reasons::of(), Units::none(), Units::none(), Changes::none(), Units::none());
    }

    /** This, for a change: the lines it added or modified in each source file, and why it reached what it did. */
    public function reaching(Changes $changed, Reasons $reach): self
    {
        return clone($this, ['changed' => $changed, 'reach' => $reach]);
    }

    /**
     * This, with the changed lines of each source file that no test runs, as
     * the coverage map the plan was made from says.
     */
    public function untesting(Changes $untested): self
    {
        return clone($this, ['untested' => $untested]);
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

    /**
     * The lines the change added or modified, over these packages, as the
     * verdict marks the mutants on them; none for a full run.
     */
    public function lines(Packages $packages): Reach
    {
        $reach = Reach::nothing($packages);

        foreach ($this->changed as $change) {
            $reach = $reach->withLines($change->path(), $change->lines());
        }

        return $reach;
    }

    /** The changed lines of each source file no test runs; none for a full run. */
    public function untested(): Changes
    {
        return $this->untested;
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

    /** This, with the units that carry only the run's own scope's newest result. */
    public function carryingOwn(Units $carried): self
    {
        return clone($this, ['carriedOwn' => $carried]);
    }

    /** The units that carry only the run's own scope's newest result. */
    public function carriedOwn(): Units
    {
        return $this->carriedOwn;
    }
}
