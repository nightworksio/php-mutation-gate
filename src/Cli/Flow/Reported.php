<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Report\EncodedReport;
use NightWorksIO\MutationGate\Core\Report\ReportsTheEncoded;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * A verdict handed to each reporter, and what each said: where it wrote, or why it could not. A reporter
 * that writes from the verdict's JSON report is handed the one the verdict built.
 *
 * @internal
 */
final readonly class Reported
{
    /**
     * @param  list<Reporter> $reporters
     * @return list<string>
     */
    public static function by(Verdict $verdict, EncodedReport $report, array $reporters): array
    {
        $said = [];

        foreach ($reporters as $reporter) {
            $written = $reporter instanceof ReportsTheEncoded
                ? $reporter->reportFrom($verdict, $report)
                : $reporter->report($verdict);
            $said[] = $written instanceof Written ? $written->said() : $written->why();
        }

        return $said;
    }
}
