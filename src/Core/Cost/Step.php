<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/**
 * One kind of work a shard's time goes to, as its result file names it
 * (ADR-0016), whichever runner does it: each runs one after
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

    /**
     * Reading the map of every file, where the tests that read a value the
     * run's files declare are found (ADR-0037).
     */
    case ValueReaderCoverage = 'value reader coverage';

    /**
     * Scanning the codebase for the tests that read each value the run's
     * files declare, and covering each value's lines, and each line of a
     * match's arms, by them (ADR-0037).
     */
    case ValueReaders = 'value readers';

    /** Making the mutants and writing what their runs read, before any of them runs. */
    case Preparing = 'preparing';

    /**
     * The mutants' own runs, with any opening run of the runner's that
     * starts them; for a runner that times no step of its own, its whole
     * run.
     */
    case Mutation = 'mutation';

    /**
     * A split stream run's first pass, within the mutation step: its parents
     * running their test files and forking each mutant at its first test
     * (ADR-0196).
     */
    case FirstPass = 'first pass';

    /**
     * A split stream run's second pass, within the mutation step: each
     * mutant the first left forked at every covering test it did not run
     * (ADR-0196).
     */
    case SecondPass = 'second pass';

    /**
     * A split stream run's own runs, within the mutation step, from its
     * second pass's end: each mutant no stream judged, in a run of its own
     * (ADR-0117), counted as they start.
     */
    case OwnRuns = 'own runs';

    /** Reading what the mutants' runs left, with any run that shows why a run failed. */
    case Reading = 'reading';

    /** Reading the map of every file a mutant's value may be read in, for the trials. */
    case TrialCoverage = 'trial coverage';

    /** The trials of mutants the runner left uncovered on lines that are not executable (ADR-0004). */
    case Trials = 'trials';

    /** The runs of a narrowed kill's test files alone on the unmutated code, one for each set of files. */
    case Baselines = 'baselines';

    /**
     * The run again, with every test file, of the narrowed kills those files
     * alone cannot vouch for, counting those kills.
     */
    case Confirmation = 'confirmation';

    /** Proving an invocation's survivors equivalent to their originals, before they run again (ADR-0013). */
    case Equivalence = 'equivalence';

    /** The runs again, by halves, of the mutants a crashed run left unjudged (ADR-0333). */
    case Suspects = 'suspects';

    /** The run again of each survivor, alone, in a process of its own (ADR-0008, ADR-0070). */
    case Survivors = 'survivors';

    /** The unmutated control of each timed-out mutant's tests, under its limit (ADR-0008). */
    case Controls = 'controls';

    /** The runs of heavy mutants' parts and of those parts' controls, round after round (ADR-0008). */
    case HeavyParts = 'heavy parts';

    /** Static analysis's checks of the shard's survivors. */
    case StaticCheck = 'static check';
}
