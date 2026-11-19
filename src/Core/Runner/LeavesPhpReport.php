<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A runner whose run of the suite under coverage leaves php-code-coverage's
 * PHP report, which a map engine reads as data rather than run (ADR-0119).
 * A runner that leaves another report, or says nothing of its report, is
 * read by itself alone.
 *
 * @internal
 */
interface LeavesPhpReport
{
    /** The report a run the project started in this job left, as the runner reads it; none where it left none. */
    public function report(CoverageRan $ran): RanReport|NotGiven;
}
