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

    /** The judgement a status comes to before any triage: a timeout is a kill until triage says otherwise. */
    public static function reported(MutantStatus $status): self
    {
        return match ($status) {
            MutantStatus::Killed => self::Killed,
            MutantStatus::Survived => self::Survived,
            MutantStatus::Uncovered => self::Uncovered,
            MutantStatus::TimedOut => self::KilledByTimeout,
            MutantStatus::Errored => self::Errored,
            MutantStatus::Unjudged => self::Unjudged,
            MutantStatus::IgnoredByMarker => self::IgnoredByMarker,
        };
    }

    /** How a mutant judged so enters the score, with uncovered mutants counted or excluded. */
    public function scoring(Uncovered $uncovered): Scoring
    {
        return match ($this) {
            self::Killed, self::Errored, self::KilledByTimeout => Scoring::Killed,
            self::Ignored, self::IgnoredByMarker => Scoring::LeftOut,
            self::Uncovered => $uncovered === Uncovered::Exclude ? Scoring::LeftOut : Scoring::NotKilled,
            self::Survived, self::Unjudged, self::Flaky, self::TooSlowToJudge => Scoring::NotKilled,
        };
    }
}
