<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

/**
 * A list of Infection's JSON log (`logs.json`), each of the mutants of one
 * outcome. A mutant whose process errored is a kill no test is named for,
 * which stands only on a fatal error PHP recorded or an unmutated control
 * (ADR-0014, ADR-0108); one that does not parse is an error the parser
 * itself evidences.
 *
 * @internal
 */
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
            self::KilledByStaticAnalysis => MutantStatus::KilledByStaticAnalysis,
            self::Escaped => MutantStatus::Survived,
            self::Killed, self::Errored => MutantStatus::Killed,
            self::SyntaxErrors => MutantStatus::Errored,
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
