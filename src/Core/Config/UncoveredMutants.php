<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** How uncovered mutants count in a score (ADR-0003). */
enum UncoveredMutants: string
{
    /** As not killed. */
    case Count = 'count';

    /** Not at all: left out, as ignored ones are, and still listed. */
    case Exclude = 'exclude';
}
