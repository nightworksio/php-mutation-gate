<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** Whether a mutant no test runs counts against the score, or is left out of it. */
enum Uncovered: string
{
    case Count = 'count';
    case Exclude = 'exclude';
}
