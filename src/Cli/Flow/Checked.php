<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * What static analysis's checks of a shard's survivors came to: its mutants,
 * each survivor the analyser rejected killed by static analysis, and the
 * checks' time and the survivors they left unchecked.
 */
final readonly class Checked
{
    public function __construct(public Mutants $mutants, public SurvivorChecks $checks)
    {
    }
}
