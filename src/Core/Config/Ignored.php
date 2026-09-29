<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Time\Day;

/** An entry of `ignores.entries`: an IgnoredMutant or an IgnoredPattern (ADR-0008). */
interface Ignored
{
    /** Why the mutants it matches cannot be killed. */
    public function reason(): string;

    /** The day it stops applying, or none. */
    public function expires(): Day|Absent;
}
