<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/** An invocation's mutants once its survivors ran again, and those of them the second run killed. */
final readonly class Confirmed
{
    public function __construct(public Mutants $mutants, public MutantIds $flaky)
    {
    }
}
