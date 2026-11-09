<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

/**
 * A speed-up of the local loop that changes no result, each a key of
 * `local` and on by default (ADR-0120).
 */
enum LocalSwitch: string
{
    /** `watch` looks through `git status` and hashes only what differs. */
    case WatchStatus = 'watchStatus';

    /** A coverage run of one test file runs in one process. */
    case CoverageAlone = 'coverageAlone';

    /** A set of warm workers boots no more workers than it has runs. */
    case SizedPools = 'sizedPools';

    /** A round lists the tests once, and a later round reuses the list while no test file moved. */
    case ListOnce = 'listOnce';

    /** `watch` prints what each batch judged as it ends. */
    case StreamBatches = 'streamBatches';
}
