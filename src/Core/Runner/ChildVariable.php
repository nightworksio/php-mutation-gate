<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * The variables the gate sets for a process of more than one runner, which
 * that runner's plugin or extension reads. Each adapter's own enum of the
 * variables it sets names these from here.
 */
enum ChildVariable: string
{
    /** The file the plugin or the extension records each mutant's or each test's result to. */
    case Results = 'MUTATION_GATE_RESULTS';

    /** The file in which the plugin, or the wrapper, says whether the mutated file ran. */
    case Guard = 'MUTATION_GATE_GUARD';

    /** The least a patched runner allows one mutant: `timeouts.seconds` (ADR-0008, decision 2). */
    case MutantFloor = 'MUTATION_GATE_MUTANT_FLOOR';

    /**
     * How long a run of no test took to start on the machine the run is on,
     * which a patched runner lays each mutant's limit on (ADR-0008, decision 2).
     */
    case MutantStartUp = 'MUTATION_GATE_MUTANT_START_UP';

    /** The lower floor of the silence limit of the mutators `timeouts.tighter` lists (see TighterVariables). */
    case TighterFloor = 'MUTATION_GATE_TIGHTER_FLOOR';

    /** The mutators `timeouts.tighter` lists, by their short names, one after another (see TighterVariables). */
    case TighterMutators = 'MUTATION_GATE_TIGHTER_MUTATORS';
}
