<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

/** What the shell measures a deadline on: the system's clock, or one a test drives. */
interface Clock
{
    /** The nanoseconds it reads, from any start. */
    public function nanoseconds(): int;
}
