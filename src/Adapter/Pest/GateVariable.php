<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;

/**
 * An environment variable the Pest adapter sets for this package's plugin,
 * or for the code `pest:patch` writes into pest-plugin-mutate. Only the
 * command that needs one sets it, and no Pest the gate starts inherits it.
 *
 * @internal
 */
enum GateVariable: string
{
    /** The results file the plugin records a mutation run to. */
    case Results = ChildVariable::Results->value;

    /** How many mutants a mutation run runs at once: its pool's processes (see MutantsAtOnce). */
    case AtOnce = 'MUTATION_GATE_AT_ONCE';

    /**
     * `set`, where a patched run selects a mutant's covering tests too many for a filter by their ids, named in
     * a file (see LongFilterSet, ADR-0138).
     */
    case LongFilter = 'MUTATION_GATE_LONG_FILTER';

    /** `judge-each`, where every mutant is judged by its own run, twins of one file among them (ADR-0137). */
    case Duplicates = 'MUTATION_GATE_DUPLICATES';

    /** The coverage map another job handed over, which a patched shard's opening run reads. */
    case SharedCoverage = 'MUTATION_GATE_SHARED_COVERAGE';

    /** The whole suite's seconds, one test after another, which Pest's own limit is worked out from. */
    case SuiteSeconds = 'MUTATION_GATE_SUITE_SECONDS';

    /** The least a patched run allows one mutant: `timeouts.seconds` (see MutantTime). */
    case MutantFloor = ChildVariable::MutantFloor->value;

    /** The most a patched run allows one mutant: `timeouts.most`, or the most a run again asks for (see MutantTime). */
    case MutantCap = 'MUTATION_GATE_MUTANT_CAP';

    /** How long a run of no test took to start on this machine (see MutantTime). */
    case MutantStartUp = ChildVariable::MutantStartUp->value;

    /** How long a run gets to start where nothing measured its start-up (see StartUpVariable). */
    case UnmeasuredStartUp = ChildVariable::UnmeasuredStartUp->value;

    /** How many times its covering tests' own time a patched run allows one mutant (see MutantTime). */
    case MutantMultiple = ChildVariable::MutantMultiple->value;

    /** The lower floor of the silence limit of the mutators `timeouts.tighter` lists (see TighterVariables). */
    case TighterFloor = ChildVariable::TighterFloor->value;

    /** The mutators `timeouts.tighter` lists (see TighterVariables). */
    case TighterMutators = ChildVariable::TighterMutators->value;

    /** How many times its own time one test of a mutant's run may run (see PerTestVariables). */
    case TestMultiple = ChildVariable::TestMultiple->value;

    /** The least one test of a mutant's run may run for (see PerTestVariables). */
    case TestFloor = ChildVariable::TestFloor->value;

    /** The canary group a patched shard's opening run is. */
    case Canary = 'MUTATION_GATE_CANARY';

    /** The file the plugin's guard writes what a trial run loaded to. */
    case Guard = ChildVariable::Guard->value;

    /** The file the plugin writes each test's name to. */
    case Names = 'MUTATION_GATE_NAMES';

    /** The directory the plugin reads each mutant's order from. */
    case Order = 'MUTATION_GATE_ORDER';

    /**
     * The mutated copy whose order a replay of its own run takes, where the
     * copy the replay serves is another (see PrefixReplays).
     */
    case OrderOf = 'MUTATION_GATE_ORDER_OF';

    /** How many tests a replay of a kill's own run runs before it stops (see ReplayStop). */
    case StopAfter = 'MUTATION_GATE_STOP_AFTER';

    /** The file listing the native ids of the only mutants a patched run again makes (see OnlyList). */
    case Only = 'MUTATION_GATE_ONLY';

    /** The file listing the mutators a patched run leaves out of the files it prunes them in (see PrunedList). */
    case Pruned = ChildVariable::Pruned->value;

    /** Set where a patched run loads only the test files each mutant's covering tests need (see CoveringFiles). */
    case Narrow = 'MUTATION_GATE_NARROW';

    /** How much of the kill matrix a mutation run records: every killer where it is `full` (see EveryKiller). */
    case KillMatrix = 'MUTATION_GATE_KILL_MATRIX';

    /**
     * The file in which the gate names the mutants static analysis rejected,
     * which the plugin waits for once every planned mutant is written (see
     * Verdicts); unset where the gate checks none before their tests.
     */
    case Verdicts = 'MUTATION_GATE_VERDICTS';

    /** The directory a patched run's warm workers share, where its mutants' runs fork from them (see Warm\Pool). */
    case Warm = 'MUTATION_GATE_WARM';

    /** How many seconds a warm worker's starter waits for it to start: `runner.workerWait`. */
    case WorkerWait = 'MUTATION_GATE_WORKER_WAIT';

    /**
     * The file a warm worker's client writes the most memory its forked run
     * held to, where an unmutated control of the tail runs through one (see
     * Warm\Client); a control's launcher cannot count a process it did not wait for.
     */
    case Peak = 'MUTATION_GATE_PEAK';

    /** The file of bridges the plugin loads, through which Pest makes registered mutators' mutants (see Bridges). */
    case Mutators = 'MUTATION_GATE_MUTATORS';

    /** The file each process of a coverage run appends every test it finished to, with its seconds (see TimedTests). */
    case TimedTests = 'MUTATION_GATE_TIMED_TESTS';

    /**
     * Set in a mutant's own run where its mutator is pinned by source, so the
     * run serves its file through the wrapper a test reads it by (see
     * ServedClasses); unset in every other run.
     */
    case SourcePinned = 'MUTATION_GATE_SOURCE_PINNED';

    /** Set where a patched run compiles each file's mutants into a schema (see Schemata). */
    case Schemata = 'MUTATION_GATE_SCHEMATA';

    /** The schema a mutant's own run serves in its file's place, where its file has one (see Schemata). */
    case Schema = 'MUTATION_GATE_SCHEMA';

    /** The id of the mutant a schema's switch puts in force in a mutant's own run (see Schemata). */
    case SchemaOn = 'MUTATION_GATE_SCHEMA_ON';

    /** Set where a patched run judges its mutants in split streams (ADR-0117). */
    case Split = 'MUTATION_GATE_SPLIT';

    /** The most children a split stream holds at once: `splitStream.children` (see Split\StreamSettings). */
    case SplitChildren = 'MUTATION_GATE_SPLIT_CHILDREN';

    /** Set where a split stream forks a pure expression's mutant only where it changes the value (StreamSettings). */
    case SplitChangeCheck = 'MUTATION_GATE_SPLIT_CHANGE_CHECK';

    /** Set where a split stream forks each mutant first at its likely killer (see Split\StreamSettings). */
    case SplitKillersFirst = 'MUTATION_GATE_SPLIT_KILLERS_FIRST';

    /**
     * The part of the test files whose tests judge a patched run's mutants,
     * and of how many, as `<part>/<parts>`, where a run cuts its test files
     * across its shards (see Split\StreamSettings).
     */
    case SplitPart = 'MUTATION_GATE_SPLIT_PART';

    /** The plan a split stream's own Pest run reads, unset in every other run (see Split\Stream). */
    case SplitPlan = 'MUTATION_GATE_SPLIT_PLAN';
}
