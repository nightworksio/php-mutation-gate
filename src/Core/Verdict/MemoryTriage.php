<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

/**
 * Memory triage (ADR-0004, decision 9): a mutant whose process ran out of
 * the memory cap, whose judging tests, run unmutated under the same cap and
 * served as it was, finished under that cap, needed far more than its tests
 * and ran away, and is killed by the memory cap; any other, and one whose
 * tests never ran so, is too heavy to judge. The run is the mutant's
 * unmutated control (see MemoryControls), its peak the mutant's need.
 */
final readonly class MemoryTriage
{
    private function __construct()
    {
    }

    public static function standard(): self
    {
        return new self();
    }

    /** What a mutant comes to: as its status reports it, and one out of memory as triage judges it. */
    public function judged(Mutant $mutant): MutantJudgement
    {
        $cap = $mutant->limit();
        $peak = $mutant->unmutatedNeed();

        return $mutant->status() === MutantStatus::OutOfMemory
            && $cap instanceof MemoryCap
            && $peak instanceof MemoryCap
            && (! $cap->caps() || $peak->bytes() <= $cap->bytes())
            ? MutantJudgement::KilledByMemoryCap
            : MutantJudgement::reported($mutant->status());
    }
}
