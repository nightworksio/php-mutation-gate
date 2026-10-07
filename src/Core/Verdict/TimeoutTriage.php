<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * Timeout triage (ADR-0008, decision 2): a timed-out mutant whose judging
 * tests, run unmutated under the same limit and served as it was, finished
 * within that limit broke something, and is killed by timeout; any other,
 * and one whose tests never ran so, is too slow to judge. The time of that
 * run is a trial's own run of its tests on their own, or the unmutated
 * control of the mutant (see TimeoutControls). Under `timeouts.mode:
 * unjudged` every timeout is too slow to judge.
 */
final readonly class TimeoutTriage
{
    private function __construct(private TimeoutMode $mode)
    {
    }

    public static function under(TimeoutMode $mode): self
    {
        return new self($mode);
    }

    /** What a mutant comes to: as its status reports it, and a timeout as triage judges it. */
    public function judged(Mutant $mutant): MutantJudgement
    {
        $limit = $mutant->limit();
        $time = $mutant->unmutatedNeed();

        return $mutant->status() === MutantStatus::TimedOut
            && $this->mode === TimeoutMode::Confirm
            && $limit instanceof Seconds
            && $time instanceof Seconds
            && $time->seconds() <= $limit->seconds()
            ? MutantJudgement::KilledByTimeout
            : MutantJudgement::reported($mutant->status());
    }
}
