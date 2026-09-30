<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What a shard's runs came to: every invocation's mutants, timeouts retried
 * and timed, the survivors a second run killed, the held units it did not
 * mutate because their holding tests miss lines of them, and the units its
 * budget ran out before.
 */
final readonly class Mutated
{
    public function __construct(
        public MutationResult $result,
        public MutantIds $flaky,
        public HeldMisses $misses,
        public Units $unjudged,
    ) {
    }
}
