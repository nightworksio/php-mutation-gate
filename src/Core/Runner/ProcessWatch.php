<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * What the gate does while a process it runs is running, each time it looks
 * at the process: a hand-off the process waits on, such as the static checks
 * a patched Pest waits for before its mutants run (ADR-0001, decision 2;
 * ADR-0020, decision 12).
 */
interface ProcessWatch
{
    /** Looks once more, while the process runs; never after it has ended. */
    public function look(): void;
}
