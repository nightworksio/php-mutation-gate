<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;

/**
 * A reporter that writes from a verdict's JSON report, which the verdict
 * builds and encodes once for every such reporter and its kept copy.
 *
 * @internal
 */
interface ReportsTheEncoded
{
    /** The verdict, written from its JSON report. */
    public function reportFrom(Verdict $verdict, EncodedReport $report): Written|NotWritten;
}
