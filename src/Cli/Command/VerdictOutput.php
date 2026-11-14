<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

/**
 * What `--output` prints a verdict as: the console's report, one line per
 * result for an editor, or, in `watch`, one event per judged mutant.
 *
 * @internal
 */
enum VerdictOutput: string
{
    case Console = 'console';
    case Problems = 'problems';

    /** One line of JSON per judged mutant as its batch ends, which only `watch` prints (ADR-0146). */
    case Events = 'events';
}
