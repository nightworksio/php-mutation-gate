<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

/** A list of Infection's JSON log (`logs.json`), each of the mutants of one outcome. */
enum LogList: string
{
    case Killed = 'killed';
    case KilledByStaticAnalysis = 'killedByStaticAnalysis';
    case Escaped = 'escaped';
    case Errored = 'errored';
    case SyntaxErrors = 'syntaxErrors';
    case Timeouted = 'timeouted';
    case Uncovered = 'uncovered';
    case Ignored = 'ignored';

    /** The count in the log's `stats` that the list must match. */
    public function count(): string
    {
        return match ($this) {
            self::Killed => 'killedCount',
            self::KilledByStaticAnalysis => 'killedByStaticAnalysisCount',
            self::Escaped => 'escapedCount',
            self::Errored => 'errorCount',
            self::SyntaxErrors => 'syntaxErrorCount',
            self::Timeouted => 'timeOutCount',
            self::Uncovered => 'notCoveredCount',
            self::Ignored => 'ignoredCount',
        };
    }

    /** The status of the list's mutants, as Infection reported them. */
    public function status(): MutantStatus
    {
        return match ($this) {
            self::Killed => MutantStatus::Killed,
            self::KilledByStaticAnalysis => MutantStatus::KilledByStaticAnalysis,
            self::Escaped => MutantStatus::Survived,
            self::Errored, self::SyntaxErrors => MutantStatus::Errored,
            self::Timeouted => MutantStatus::TimedOut,
            self::Uncovered => MutantStatus::Uncovered,
            self::Ignored => MutantStatus::IgnoredByMarker,
        };
    }

    /** Whether the output of the list's mutants names the tests that killed them. */
    public function namesKillers(): bool
    {
        return $this === self::Killed;
    }

    /** Whether a mutant of the list may have had its own process end by running out of memory. */
    public function mayRunOutOfMemory(): bool
    {
        return $this === self::Killed || $this === self::Errored;
    }
}
