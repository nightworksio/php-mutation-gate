<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * The variables the gate sets for a process of more than one runner, which
 * that runner's plugin or extension reads. Each adapter's own enum of the
 * variables it sets names these from here.
 *
 * @internal
 */
enum ChildVariable: string
{
    /** The file the plugin or the extension records each mutant's or each test's result to. */
    case Results = 'MUTATION_GATE_RESULTS';

    /** The file in which the plugin, or the wrapper, says whether the mutated file ran. */
    case Guard = 'MUTATION_GATE_GUARD';

    /** The least a patched runner allows one mutant: `timeouts.seconds` (ADR-0008). */
    case MutantFloor = 'MUTATION_GATE_MUTANT_FLOOR';

    /**
     * How long a run of no test took to start on the machine the run is on,
     * which a patched runner lays each mutant's limit on (ADR-0008).
     */
    case MutantStartUp = 'MUTATION_GATE_MUTANT_START_UP';

    /** How many times its covering tests' own time a patched runner allows a mutant: `timeouts.multiple` (ADR-0008). */
    case MutantMultiple = 'MUTATION_GATE_MUTANT_MULTIPLE';

    /** How long a run gets to start where nothing measured its start-up, `timeouts.startUp` (see StartUpVariable). */
    case UnmeasuredStartUp = 'MUTATION_GATE_UNMEASURED_START_UP';

    /** The most compressed bytes of a handed coverage map, `proofs.limits.handoff.packed` (see HandoffLimits). */
    case HandoffPacked = 'MUTATION_GATE_HANDOFF_PACKED';

    /** The most decompressed bytes of a handed coverage map, `proofs.limits.handoff.unpacked` (see HandoffLimits). */
    case HandoffUnpacked = 'MUTATION_GATE_HANDOFF_UNPACKED';

    /** How long to wait between looks at a process, `runner.poll`, where the config sets it (see Polling). */
    case Poll = 'MUTATION_GATE_POLL';

    /**
     * The digest of the test files a runner in this process lists the tests
     * of, set by `watch` each round, so a listing is reused only while no
     * test file changed (ADR-0120).
     */
    case TestsKey = 'MUTATION_GATE_TESTS_KEY';

    /** How long a warm worker and its client wait for a wake before they look anyway: `local.childWait` (ADR-0120). */
    case WarmWait = 'MUTATION_GATE_WARM_WAIT';

    /** Set where a coverage run records what its tests loaded, under `reach.lockMoves: loaded` (ADR-0115). */
    case RecordsLoads = 'MUTATION_GATE_RECORDS_LOADS';

    /** The file listing the mutators a patched runner leaves out of the files it prunes them in (ADR-0025). */
    case Pruned = 'MUTATION_GATE_PRUNED';

    /** The lower floor of the silence limit of the mutators `timeouts.tighter` lists (see TighterVariables). */
    case TighterFloor = 'MUTATION_GATE_TIGHTER_FLOOR';

    /** The mutators `timeouts.tighter` lists, by their short names, one after another (see TighterVariables). */
    case TighterMutators = 'MUTATION_GATE_TIGHTER_MUTATORS';

    /** How many times its own time one test of a mutant's run may run: `timeouts.perTest` (see PerTestVariables). */
    case TestMultiple = 'MUTATION_GATE_TEST_MULTIPLE';

    /** The least one test of a mutant's run may run for: `timeouts.testFloor` (see PerTestVariables). */
    case TestFloor = 'MUTATION_GATE_TEST_FLOOR';
}
