<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * What a runner reports of one mutant, the same for every runner. A mutant
 * the runner's own config ignored, where the config allows that, is ignored by
 * a native marker. A skipped mutant is one Infection never ran, because its
 * covering tests alone take as long as its timeout.
 */
enum MutantStatus: string
{
    case Killed = 'killed';
    /** A static analyser rejected it, by an error its original does not have (ADR-0020, decision 10). */
    case KilledByStaticAnalysis = 'killed-by-static-analysis';
    case Survived = 'survived';
    case Uncovered = 'uncovered';
    case TimedOut = 'timed-out';
    case Errored = 'errored';
    case Unjudged = 'unjudged';
    case IgnoredByMarker = 'ignored-by-marker';
    case Skipped = 'skipped';

    /** Whether the mutant's time ran out: it timed out, or was skipped for taking as long as its timeout. */
    public function ranOutOfTime(): bool
    {
        return $this === self::TimedOut || $this === self::Skipped;
    }
}
