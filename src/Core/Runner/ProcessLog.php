<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * Where the gate says, while it waits on the processes it runs, what is
 * running and for how long, so a run that is slow or stalled can be read
 * from its log as it runs.
 */
interface ProcessLog
{
    /** Says one line. */
    public function say(string $line): void;
}
