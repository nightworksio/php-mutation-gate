<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * `runner.workers`: how a native runner, and Pest under `pest.patch`, start
 * each mutant's run and each run of the verification tail after it: the
 * unmutated controls, baselines, trials, confirmation and survivors' runs
 * again (ADR-0023, ADR-0070). It can move a result, as a boot's state is a
 * source of state.
 */
enum Workers: string
{
    /**
     * A worker per place boots once and forks a child for each mutant and
     * each run of the tail, where the runner is native, its PHP forks and the
     * boot passes the guard, or where Pest runs under `pest.patch` and its
     * PHP forks; each runs in a fresh process otherwise.
     */
    case Fork = 'fork';

    /** Each mutant's run forks as under `fork`, and each run of the tail runs in a fresh process. */
    case Mutants = 'mutants';

    /** Each mutant's run, and each run of the tail, runs in a fresh process. */
    case Fresh = 'fresh';

    /** Whether each mutant's own run forks from a warm worker. */
    public function forksMutants(): bool
    {
        return $this !== self::Fresh;
    }

    /** Whether each run of the verification tail forks from a warm worker. */
    public function forksTheTail(): bool
    {
        return $this === self::Fork;
    }
}
