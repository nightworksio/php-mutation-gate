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
}
