<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

/** The qualities of software SonarQube rates, of those a mutant counted as not killed affects. */
enum SonarQuality: string
{
    case Reliability = 'RELIABILITY';
    case Security = 'SECURITY';
}
