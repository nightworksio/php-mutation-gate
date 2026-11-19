<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\PendingOutline;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

/**
 * A map engine that reads php-code-coverage's PHP report a run left as data,
 * never running it, into the map the gate writes of it (ADR-0119). The map it
 * writes holds the text the gate writes of the report; where it declines, the
 * gate reads the report itself.
 *
 * @internal
 */
interface ConvertsPhpReports
{
    /**
     * The outline of the map the gate builds of a run's report, read while
     * the gate goes on: the map's tests in its order, the files each ran a
     * line of, and each file by the name the report gives it; or why there
     * is none.
     */
    public function outlining(RanReport $report): PendingOutline;

    /**
     * The map the gate writes of a run's report, written gzipped at a path of
     * the project: each file at the path the outline gives it, admitted as the
     * conversion's admission says, with where it was measured and its
     * entries' keys; or why it was not.
     */
    public function convert(RanReport $report, Conversion $conversion, Path $into): Written|NotWritten;
}
