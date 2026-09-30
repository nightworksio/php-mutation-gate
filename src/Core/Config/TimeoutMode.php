<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** How a timed-out mutant is judged (ADR-0008). */
enum TimeoutMode: string
{
    /** Killed when its covering tests normally finish in under half the limit, too slow to judge otherwise. */
    case Confirm = 'confirm';

    /** Always too slow to judge. */
    case Unjudged = 'unjudged';
}
