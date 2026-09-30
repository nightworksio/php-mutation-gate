<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

/** The top-level keys of Infection's config an import maps into the gate's own. */
enum Mapped: string
{
    case Source = 'source';
    case MinMsi = 'minMsi';
    case MinCoveredMsi = 'minCoveredMsi';
    case Timeout = 'timeout';
    case TimeoutsAsEscaped = 'timeoutsAsEscaped';
    case Logs = 'logs';
}
