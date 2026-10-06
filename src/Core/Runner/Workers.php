<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * `runner.workers`: how a native runner starts each mutant's run (ADR-0023,
 * decision 14). It can move a result, as a boot's state is a source of state.
 */
enum Workers: string
{
    /**
     * A worker per place boots once and forks a child for each mutant, where
     * the runner is native, its PHP forks and the boot passes the guard; each
     * mutant runs in a fresh process otherwise.
     */
    case Fork = 'fork';

    /** Each mutant runs in a fresh process. */
    case Fresh = 'fresh';
}
