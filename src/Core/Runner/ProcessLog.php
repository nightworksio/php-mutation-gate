<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * Where the gate says, while it waits on the processes it runs, what is
 * running and for how long, so a run that is slow or stalled can be read
 * from its log as it runs.
 *
 * @internal
 */
interface ProcessLog
{
    /**
     * The variable of the gate's own process that names a file each line is
     * also kept in, while a shard's progress is left (ADR-0157).
     */
    public const string KEPT = 'MUTATION_GATE_SAID';

    /** Says one line. */
    public function say(string $line): void;
}
