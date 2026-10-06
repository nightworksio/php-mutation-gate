<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** How a timed-out mutant is judged (ADR-0008). */
enum TimeoutMode: string
{
    /** Killed when its limit allowed its covering tests their multiple of their own time; else too slow to judge. */
    case Confirm = 'confirm';

    /** Always too slow to judge. */
    case Unjudged = 'unjudged';
}
