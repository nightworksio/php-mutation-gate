<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

/** The words every report prints for how the gate judged a mutant. */
final readonly class Label
{
    public static function of(MutantJudgement $judgement): string
    {
        return match ($judgement) {
            MutantJudgement::KilledByTimeout => 'killed by timeout',
            MutantJudgement::KilledByStaticAnalysis => 'killed by static analysis',
            MutantJudgement::TooSlowToJudge => 'too slow to judge',
            MutantJudgement::KilledByMemoryCap => 'killed by the memory cap',
            MutantJudgement::TooHeavyToJudge => 'too heavy to judge',
            MutantJudgement::IgnoredByMarker => 'ignored by a native marker',
            MutantJudgement::Equivalent => 'equivalent, proven',
            MutantJudgement::Killed,
            MutantJudgement::Errored,
            MutantJudgement::Survived,
            MutantJudgement::Uncovered,
            MutantJudgement::Unjudged,
            MutantJudgement::Flaky,
            MutantJudgement::Ignored => $judgement->value,
        };
    }
}
