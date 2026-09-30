<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;

/**
 * What the gate made of one mutant: its runner's status, after timeout and
 * flaky triage and the ignore list.
 */
enum MutantJudgement: string
{
    case Killed = 'killed';
    /** The mutant crashed the tests. */
    case Errored = 'errored';
    /** It timed out, and timeout triage judged that a kill. */
    case KilledByTimeout = 'killed-by-timeout';
    case Survived = 'survived';
    case Uncovered = 'uncovered';
    /** It was never run to an answer, for the reason its record gives. */
    case Unjudged = 'unjudged';
    /** The same code gave two answers. */
    case Flaky = 'flaky';
    /** It timed out or was skipped, and triage could not tell a kill from a hang. */
    case TooSlowToJudge = 'too-slow-to-judge';
    /** An entry in the ignore list matched it. */
    case Ignored = 'ignored';
    /** The runner's own marker or config ignored it, where the config allows that. */
    case IgnoredByMarker = 'ignored-by-marker';
    /** It survived, and compiles to the same program as the original, so no test can fail on it (ADR-0013). */
    case Equivalent = 'equivalent';

    /**
     * The judgement a status comes to before any triage: a timeout, or a
     * mutant skipped as too slow to run, is too slow to judge until timeout
     * triage confirms it a kill.
     */
    public static function reported(MutantStatus $status): self
    {
        return match ($status) {
            MutantStatus::Killed => self::Killed,
            MutantStatus::Survived => self::Survived,
            MutantStatus::Uncovered => self::Uncovered,
            MutantStatus::TimedOut => self::TooSlowToJudge,
            MutantStatus::Errored => self::Errored,
            MutantStatus::Unjudged => self::Unjudged,
            MutantStatus::IgnoredByMarker => self::IgnoredByMarker,
            MutantStatus::Skipped => self::TooSlowToJudge,
        };
    }

    /**
     * Whether a test is what a mutant so judged asks for: a survivor, or an
     * uncovered mutant, which `stub` writes one for and a cluster holds
     * (ADR-0015, decision 1).
     */
    public function asksForATest(): bool
    {
        return match ($this) {
            self::Survived, self::Uncovered => true,
            self::Killed,
            self::Errored,
            self::KilledByTimeout,
            self::Unjudged,
            self::Flaky,
            self::TooSlowToJudge,
            self::Ignored,
            self::IgnoredByMarker,
            self::Equivalent => false,
        };
    }

    /** How a mutant judged so enters the score, with uncovered mutants counted or excluded. */
    public function scoring(Uncovered $uncovered): Scoring
    {
        return match ($this) {
            self::Killed, self::Errored, self::KilledByTimeout => Scoring::Killed,
            self::Ignored, self::IgnoredByMarker, self::Equivalent => Scoring::LeftOut,
            self::Uncovered => $uncovered === Uncovered::Exclude ? Scoring::LeftOut : Scoring::NotKilled,
            self::Survived, self::Unjudged, self::Flaky, self::TooSlowToJudge => Scoring::NotKilled,
        };
    }
}
