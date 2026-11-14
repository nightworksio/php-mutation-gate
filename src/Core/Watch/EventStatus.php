<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Watch;

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What an event of `watch` says of a mutant (ADR-0146, ADR-0133): killed,
 * survived, out of time, not judged yet, or proven equivalent.
 *
 * @internal
 */
enum EventStatus: string
{
    case Killed = 'killed';
    case Survived = 'survived';
    case Timeout = 'timeout';
    case Pending = 'pending';
    case Equivalent = 'equivalent';

    /** What an event says of a mutant a run reported so; nothing of one a marker ignored, which no run judges. */
    public static function of(MutantStatus $status): self|NotGiven
    {
        return match ($status) {
            MutantStatus::Killed,
            MutantStatus::KilledByStaticAnalysis,
            MutantStatus::Errored,
            MutantStatus::OutOfMemory => self::Killed,
            MutantStatus::Survived, MutantStatus::Uncovered => self::Survived,
            MutantStatus::TimedOut, MutantStatus::Skipped => self::Timeout,
            MutantStatus::Unjudged, MutantStatus::FailsUnmutated, MutantStatus::NotReached => self::Pending,
            MutantStatus::Equivalent => self::Equivalent,
            MutantStatus::IgnoredByMarker => NotGiven::value(),
        };
    }
}
