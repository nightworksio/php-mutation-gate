<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** What the runner times its steps on: the system's clock, or one a test drives. */
interface Clock
{
    /** The seconds it reads, from any start. */
    public function seconds(): Seconds;
}
