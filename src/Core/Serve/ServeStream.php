<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Serve;

/**
 * What one line of a daemon's answer carries (ADR-0078).
 *
 * @internal
 */
enum ServeStream: string
{
    /** Text the command wrote to its output. */
    case Output = 'printed';

    /** Text the command wrote to its error output. */
    case Error = 'warned';

    /** The command's exit code, which ends the answer. */
    case Exit = 'exit';

    /** Why the daemon refused the request, which ends the answer. */
    case Refusal = 'refused';
}
