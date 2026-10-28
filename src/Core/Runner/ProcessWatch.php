<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * What the gate does while a process it runs is running, each time it looks
 * at the process: a hand-off the process waits on, such as the static checks
 * a patched Pest waits for before its mutants run (ADR-0001;
 * ADR-0020); and what it can tell of the process's progress.
 */
interface ProcessWatch
{
    /** Looks once more, while the process runs; never after it has ended. */
    public function look(): void;

    /** What the watch tells of how far the process has come, said in a log; empty where it tells nothing. */
    public function progress(): string;
}
