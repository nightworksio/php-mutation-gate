<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;

/**
 * One audience's rendering of a verdict: the console, JSON, JUnit, SARIF, an
 * annotation, a comment, a badge. A reporter that fails says so and changes
 * no exit code.
 */
interface Reporter
{
    /** Write the verdict for this reporter's audience. */
    public function report(Verdict $verdict): Written|NotWritten;
}
