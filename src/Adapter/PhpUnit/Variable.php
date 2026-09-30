<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;

/**
 * The variables the gate sets for the PHPUnit it starts for a mutant, which
 * the extension and the override read. Each names one file.
 */
enum Variable: string
{
    /** The file the extension records each test's outcome to. */
    case Results = ChildVariable::Results->value;

    /** The file the wrapper says it served the mutated file in, and the extension whether opcache could not. */
    case Guard = ChildVariable::Guard->value;

    /** The file the wrapper serves the mutated file in place of. */
    case Mutant = 'MUTATION_GATE_MUTANT';

    /** The mutated file the wrapper serves. */
    case Mutated = 'MUTATION_GATE_MUTATED';
}
