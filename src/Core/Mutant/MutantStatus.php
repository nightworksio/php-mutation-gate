<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * What a runner reports of one mutant, the same for every runner. A mutant
 * the runner's own config ignored, where the config allows that, is ignored by
 * a native marker.
 */
enum MutantStatus: string
{
    case Killed = 'killed';
    case Survived = 'survived';
    case Uncovered = 'uncovered';
    case TimedOut = 'timed-out';
    case Errored = 'errored';
    case Unjudged = 'unjudged';
    case IgnoredByMarker = 'ignored-by-marker';
}
