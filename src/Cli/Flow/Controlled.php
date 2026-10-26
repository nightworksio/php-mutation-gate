<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/** An invocation's mutants as their unmutated controls judged them, and their evidence, each vouched kill marked so. */
final readonly class Controlled
{
    public function __construct(public Mutants $mutants, public Evidences $evidence)
    {
    }
}
