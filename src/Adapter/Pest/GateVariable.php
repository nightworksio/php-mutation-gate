<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/**
 * An environment variable the Pest adapter sets for this package's plugin,
 * or for the code `pest:patch` writes into pest-plugin-mutate. Only the
 * command that needs one sets it, and no Pest the gate starts inherits it.
 */
enum GateVariable: string
{
    /** The results file the plugin records a mutation run to. */
    case Results = 'MUTATION_GATE_RESULTS';

    /** The coverage map another job handed over, which a patched shard's opening run reads. */
    case SharedCoverage = 'MUTATION_GATE_SHARED_COVERAGE';

    /** The seconds the whole suite took, one test after another, which a patched shard times mutants by. */
    case SuiteSeconds = 'MUTATION_GATE_SUITE_SECONDS';

    /** The canary group a patched shard's opening run is. */
    case Canary = 'MUTATION_GATE_CANARY';

    /** The file the plugin's guard writes what a trial run loaded to. */
    case Guard = 'MUTATION_GATE_GUARD';

    /** The file the plugin writes each test's name to. */
    case Names = 'MUTATION_GATE_NAMES';

    /** The directory the plugin reads each mutant's order from. */
    case Order = 'MUTATION_GATE_ORDER';

    /** The native ids of the only mutants a patched run again makes, comma-separated. */
    case Only = 'MUTATION_GATE_ONLY';
}
