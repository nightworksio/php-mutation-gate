<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Headroom;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

/**
 * Memory triage (ADR-0004, decision 9): a mutant whose process ran out of
 * the memory cap, where the cap leaves the room {@see Headroom} asks above
 * the most the unmutated suite's largest process held, needed far more than
 * its suite and ran away, and is killed by the memory cap; any other, and
 * one where the suite's peak is unknown, is too heavy to judge.
 */
final readonly class MemoryTriage
{
    private function __construct(private Headroom $headroom)
    {
    }

    public static function standard(): self
    {
        return new self(Headroom::standard());
    }

    /**
     * The mutants, each one that ran out of memory with the most the
     * unmutated suite's largest process held, as the plan measured it; where
     * the plan measured none, the suite's need stays unknown.
     */
    public static function weighed(Mutants $mutants, MemoryCap|NotGiven $peak): Mutants
    {
        if (! $peak instanceof MemoryCap) {
            return $mutants;
        }

        $weighed = Mutants::none();

        foreach ($mutants as $mutant) {
            $weighed = $weighed->with(
                $mutant->status() === MutantStatus::OutOfMemory ? $mutant->withUnmutatedNeed($peak) : $mutant,
            );
        }

        return $weighed;
    }

    /** What a mutant comes to: as its status reports it, and one out of memory as triage judges it. */
    public function judged(Mutant $mutant): MutantJudgement
    {
        $cap = $mutant->limit();
        $peak = $mutant->unmutatedNeed();

        return $mutant->status() === MutantStatus::OutOfMemory
            && $cap instanceof MemoryCap
            && $peak instanceof MemoryCap
            && $this->headroom->isLeftBy($cap, $peak)
            ? MutantJudgement::KilledByMemoryCap
            : MutantJudgement::reported($mutant->status());
    }
}
