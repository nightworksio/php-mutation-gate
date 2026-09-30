<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/** How one mutant enters its set's score. */
enum Scoring
{
    case Killed;
    case NotKilled;
    case LeftOut;
}
