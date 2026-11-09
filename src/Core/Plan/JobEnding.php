<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

/**
 * How the CI job of a shard that left no result ended, as the CI names it in
 * that result's place.
 *
 * @internal
 */
enum JobEnding: string
{
    /** Its runner received a shutdown signal, which stopped the job. */
    case Shutdown = 'shutdown';

    /** Its runner lost contact with the CI. */
    case Lost = 'lost';

    /** The CI cancelled it. */
    case Cancelled = 'cancelled';

    /** It ran past its time limit. */
    case TimedOut = 'timed-out';

    /** It failed. */
    case Failed = 'failed';
}
