<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

/** What `--output` prints a verdict as: the console's report, or one line per result for an editor. */
enum VerdictOutput: string
{
    case Console = 'console';
    case Problems = 'problems';
}
