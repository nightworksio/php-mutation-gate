<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The most memory the processes the gate started have held, so far
 * (ADR-0004, decision 9): what a run weighs its memory cap against.
 */
interface PeakMemory
{
    /** The peak, as the smallest cap that holds it, where the system counts one. */
    public function peak(): MemoryCap|NotGiven;
}
