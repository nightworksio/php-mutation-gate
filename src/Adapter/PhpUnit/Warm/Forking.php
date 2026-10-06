<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

/**
 * How a warm worker starts a run in a child (ADR-0023, decision 12): the
 * worker's script forks one that runs PHPUnit for the mutant and exits with
 * PHPUnit's code. The parent goes on with the child's process id.
 */
interface Forking
{
    /** The run started in a child: its process id, or below one where no child could be made. */
    public function forked(WarmRun $run): int;
}
