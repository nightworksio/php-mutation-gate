<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace as GateDirectory;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

use function sprintf;

/**
 * Where the gate leaves a survivor's code for what checks it: static
 * analysis, which reads the mutant, its original as its runner prints it and
 * as it is written (ADR-0020), and the proof of equivalence, which compiles
 * both (ADR-0130).
 *
 * @internal
 */
final readonly class CheckedFiles
{
    /** Where static analysis reads a survivor's mutated code, for its one check. */
    public static function mutant(MutantId $mutant): Path
    {
        return Path::of(sprintf('%s/staticcheck/mutants/%s.php', GateDirectory::root()->value(), $mutant->value()));
    }

    /**
     * Where static analysis reads a survivor's original printed as its runner
     * prints its mutants, for the check that it analyses as the file does.
     */
    public static function original(MutantId $mutant): Path
    {
        return Path::of(sprintf('%s/staticcheck/originals/%s.php', GateDirectory::root()->value(), $mutant->value()));
    }

    /**
     * Where static analysis reads a survivor's original as it is written,
     * beside its print, for the check that the print analyses as the file
     * does read from there.
     */
    public static function asWritten(MutantId $mutant): Path
    {
        return Path::of(sprintf('%s/staticcheck/written/%s.php', GateDirectory::root()->value(), $mutant->value()));
    }

    /** Where survivors and their originals are compiled, to prove a survivor equivalent. */
    public static function equivalence(): Path
    {
        return Path::of(sprintf('%s/equivalence', GateDirectory::root()->value()));
    }
}
