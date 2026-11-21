<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * A signal the gate sends another process or group, by the name the `kill`
 * program takes, which holds where PHP has no `pcntl` to name its number
 * (ADR-0312).
 *
 * @internal
 */
enum Signal: string
{
    /** Freezes the process where it stands, so it starts no other. */
    case Freeze = 'STOP';

    /** Ends the process at once. */
    case Kill = 'KILL';

    /** Asks the process to end. */
    case Terminate = 'TERM';
}
