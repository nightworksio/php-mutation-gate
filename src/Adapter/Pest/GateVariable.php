<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;

/**
 * An environment variable the Pest adapter sets for this package's plugin,
 * or for the code `pest:patch` writes into pest-plugin-mutate. Only the
 * command that needs one sets it, and no Pest the gate starts inherits it.
 */
enum GateVariable: string
{
    /** The results file the plugin records a mutation run to. */
    case Results = ChildVariable::Results->value;

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

    /** The lower floor of the silence limit of the mutators `timeouts.tighter` lists (see TighterVariables). */
    case TighterFloor = ChildVariable::TighterFloor->value;

    /** The mutators `timeouts.tighter` lists (see TighterVariables). */
    case TighterMutators = ChildVariable::TighterMutators->value;

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

    /** The file of bridges the plugin loads, through which Pest makes registered mutators' mutants (see Bridges). */
    case Mutators = 'MUTATION_GATE_MUTATORS';
}
