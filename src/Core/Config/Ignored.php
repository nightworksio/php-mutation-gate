<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Time\Day;

/** An entry of `ignores.entries`: an IgnoredMutant or an IgnoredPattern (ADR-0008). */
interface Ignored
{
    /** Why the mutants it matches cannot be killed. */
    public function reason(): string;

    /** The day it stops applying, or none. */
    public function expires(): Day|Absent;

    /** Whether it names this mutant: by its id, or by its file and its mutator or the mutator's family. */
    public function matches(Mutant $mutant): bool;

    /** How a report names it: the mutant's id, or the mutator and the glob. */
    public function named(): string;

    /** This entry as a config writes it. */
    public function written(PathOrigin $origin): Json;

    /** This entry as the builder's `Ignore` writes it. */
    public function php(PathOrigin $origin): string;
}
