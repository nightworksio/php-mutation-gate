<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/**
 * One kind of work a shard's time goes to, as its result file names it
 * (ADR-0016, decision 19), whichever runner does it: each runs one after
 * another within the shard, and a runner names those it does.
 */
enum Step: string
{
    /** Checking which held units their holding tests cover, before any mutant runs. */
    case HeldCoverage = 'held coverage';

    /**
     * Readying the coverage a runner's run reads: the map another job
     * handed over, read, or the tests run under coverage.
     */
    case Coverage = 'coverage';

    /** Making the mutants and writing what their runs read, before any of them runs. */
    case Preparing = 'preparing';

    /**
     * The mutants' own runs, with any opening run of the runner's that
     * starts them; for a runner that times no step of its own, its whole
     * run.
     */
    case Mutation = 'mutation';

    /** Reading what the mutants' runs left, with any run that shows why a run failed. */
    case Reading = 'reading';

    /** Reading the map of every file a mutant's value may be read in, for the trials. */
    case TrialCoverage = 'trial coverage';

    /** The trials of mutants the runner left uncovered on lines that are not executable (ADR-0004, decision 8). */
    case Trials = 'trials';

    /** The runs of a narrowed kill's test files alone on the unmutated code, one for each set of files. */
    case Baselines = 'baselines';

    /** The run again, with every test file, of the narrowed kills those files alone cannot vouch for. */
    case Confirmation = 'confirmation';

    /** The run again of each timeout whose limit was the most, with the most doubled (ADR-0008). */
    case Retry = 'retry';

    /** Proving survivors equivalent to their originals, before they run again (ADR-0013). */
    case Equivalence = 'equivalence';

    /** The run again of each survivor, alone, in a fresh process (ADR-0008). */
    case Survivors = 'survivors';

    /** Static analysis's checks of the shard's survivors. */
    case StaticCheck = 'static check';
}
