<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;

/**
 * What a shard's runs came to: every invocation's mutants, timeouts timed,
 * the survivors a second run killed, the held units it did not mutate
 * because their holding tests miss lines of them and the tests that run each
 * it did, the units its budget ran out before or its doom left, what static
 * analysis's checks of its survivors came to, and the survivor that made its
 * run certain to fail, where one did.
 */
final readonly class Mutated
{
    public function __construct(
        public MutationResult $result,
        public MutantIds $flaky,
        public HeldChecks $held,
        public Units $unjudged,
        public SurvivorChecks $checks,
        public Doomed|Undoomed $doomed,
    ) {
    }
}
