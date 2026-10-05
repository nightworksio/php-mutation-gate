<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** Where a runner runs one process at a time, so its one place is shared with no other (see WorkerSlot). */
enum NotShared
{
    case Place;
}
