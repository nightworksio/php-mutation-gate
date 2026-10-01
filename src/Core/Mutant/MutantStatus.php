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

    /** Its process ran out of the memory `runner.memory` caps it at (ADR-0004, decision 9). */
    case OutOfMemory = 'out-of-memory';

    /**
     * The status as a run's answer about the mutant: a kill by static
     * analysis and a kill by a test as one, since whether the analyser
     * checked a mutant before its tests or after them is the run's placement,
     * not the code (ADR-0020, decision 11). Survivor confirmation reads it,
     * so a survivor killed either way the second time is flaky, and so does
     * the agreement check, which compares two runs' answers (ADR-0007,
     * decision 3).
     */
    public function answer(): self
    {
        return $this === self::KilledByStaticAnalysis ? self::Killed : $this;
    }

    /** Whether the mutant's time ran out: it timed out, or was skipped for taking as long as its timeout. */
    public function ranOutOfTime(): bool
    {
        return $this === self::TimedOut || $this === self::Skipped;
    }
}
