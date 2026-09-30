<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

/** The variables the gate sets for the PHPUnit it starts for a mutant, which the extension and the override read. */
enum Variable: string
{
    /** The file the extension records each test's outcome to. */
    case Results = 'MUTATION_GATE_RESULTS';

    /** The file the override serves, and the mutated file it serves in its place. */
    case Mutant = MutantFile::VARIABLE;
}
