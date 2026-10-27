<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * `runner.workers`: how a native runner, and Pest under `pest.patch`, start
 * each mutant's run (ADR-0023). It can move a result, as a boot's state is a
 * source of state.
 */
enum Workers: string
{
    /**
     * A worker per place boots once and forks a child for each mutant, where
     * the runner is native, its PHP forks and the boot passes the guard, or
     * where Pest runs under `pest.patch` and its PHP forks; each mutant runs
     * in a fresh process otherwise.
     */
    case Fork = 'fork';

    /** Each mutant runs in a fresh process. */
    case Fresh = 'fresh';
}
