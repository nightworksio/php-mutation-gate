<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/** Where a failed run's interpretation runs nothing again to show why it failed (see OpeningIssues). */
enum NoRerun
{
    case Opening;
}
