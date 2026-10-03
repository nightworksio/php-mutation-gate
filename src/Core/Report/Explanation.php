<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Proof\Records;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;

/**
 * One mutant explained from what the gate keeps, running nothing (ADR-0014,
 * decisions 12 and 13): as the last run judged it, or as its newest record
 * holds it where the last run does not; the tests that cover it and what
 * each did; how the last run took its unit and why; and every record the
 * ledgers read hold of it.
 */
final readonly class Explanation
{
    private function __construct(
        private JudgedMutant|JudgedKill $judged,
        private KillMatrix $matrix,
        private JudgedUnit|CannotTell $unit,
        private Reasons $reach,
        private Records $history,
    ) {
    }

    /** A mutant as the last run judged it, in the unit it took and with the reach it followed. */
    public static function judged(
        JudgedMutant|JudgedKill $judged,
        KillMatrix $matrix,
        JudgedUnit|CannotTell $unit,
        Reasons $reach,
        Records $history,
    ): self {
        return new self($judged, $matrix, $unit, $reach, $history);
    }

    /** A mutant as its newest record holds it, since the last run does not, and why. */
    public static function recorded(JudgedMutant|JudgedKill $judged, CannotTell $unjudged, Records $history): self
    {
        return new self($judged, KillMatrix::none(), $unjudged, Reasons::of(), $history);
    }

    public function mutant(): JudgedMutant|JudgedKill
    {
        return $this->judged;
    }

    /** The tests that cover it and what each did; for a mutant the last run does not hold, its killers alone. */
    public function matrix(): KillMatrix
    {
        return $this->matrix;
    }

    /** The unit the last run took it in, run, proved or carried; or why the last run cannot say. */
    public function unit(): JudgedUnit|CannotTell
    {
        return $this->unit;
    }

    /** What the last run's change reached, and why. */
    public function reach(): Reasons
    {
        return $this->reach;
    }

    /** Every record the ledgers read hold of it, the newest first. */
    public function history(): Records
    {
        return $this->history;
    }
}
