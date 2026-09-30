<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What `doctor --measure` measured by running the project's suite
 * (ADR-0017, decisions 4 and 9): the whole suite's coverage map, or why the
 * run gave none; the units the suite holds, as a run finds them; and how
 * long each unit took, as the ledgers learned it; and the most resident
 * memory the suite's processes held.
 */
final readonly class Measurement
{
    private function __construct(
        private CoverageMap|CannotJudge $coverage,
        private Units $held,
        private Timings $timings,
        private MemoryCap|NotGiven $peak,
    ) {
    }

    public static function of(CoverageMap|CannotJudge $coverage, Units $held, Timings $timings): self
    {
        return new self($coverage, $held, $timings, NotGiven::value());
    }

    /** This, with the most memory the suite's processes held, as the smallest cap that holds it. */
    public function peakingAt(MemoryCap $peak): self
    {
        return clone($this, ['peak' => $peak]);
    }

    /** The map of one coverage run of the whole suite, or why the run gave none. */
    public function coverage(): CoverageMap|CannotJudge
    {
        return $this->coverage;
    }

    /** Every unit of the trees, each held path one unit. */
    public function held(): Units
    {
        return $this->held;
    }

    public function timings(): Timings
    {
        return $this->timings;
    }

    /** The most resident memory the suite's processes held, where the system counts it. */
    public function peak(): MemoryCap|NotGiven
    {
        return $this->peak;
    }
}
