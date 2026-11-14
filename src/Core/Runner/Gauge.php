<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * What the machine the gate runs on has left now, as far as its system says:
 * the memory a process can still take, its swap and the disk the gate
 * writes to.
 *
 * @internal
 */
interface Gauge
{
    public function remaining(): Remaining;
}
