<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/** What the shell measures a deadline on: the system's clock, or one a test drives. */
interface Clock
{
    /** The seconds it reads, from any start. */
    public function seconds(): float;
}
