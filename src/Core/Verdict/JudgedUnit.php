<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * One unit of a judged tree, whether its result was run, proved or carried,
 * and, for a proved or carried one, the run whose proof it came from.
 */
final readonly class JudgedUnit
{
    private function __construct(private Unit $unit, private Origin $origin, private Run|ThisRun $run)
    {
    }

    public static function of(Unit $unit, Origin $origin): self
    {
        return new self($unit, $origin, ThisRun::result());
    }

    /** This unit, with its result from the proof this run established (ADR-0015, decision 8). */
    public function withRun(Run $run): self
    {
        return clone($this, ['run' => $run]);
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function origin(): Origin
    {
        return $this->origin;
    }

    /** The run whose proof the result came from; this run where no proof names one. */
    public function run(): Run|ThisRun
    {
        return $this->run;
    }
}
