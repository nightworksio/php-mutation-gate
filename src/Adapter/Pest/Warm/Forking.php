<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Baseline;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a Pest run's warm worker starts a mutant's run in a child (ADR-0023):
 * its script forks one that takes on the run's environment, directory and
 * command line, readies its test databases from the worker's baseline to
 * report to the given file, and runs Pest's script. The worker goes on with
 * the child's process id.
 *
 * @internal
 */
interface Forking
{
    /** The run started in a child: its process id, or none where no child could be made. */
    public function forked(Request $request, Baseline|NotGiven $baseline, string $report): int|NotGiven;
}
