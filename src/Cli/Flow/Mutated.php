<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/**
 * What a shard's runs came to: every invocation's mutants, timeouts retried
 * and timed, the survivors a second run killed, and the held units it did
 * not mutate because their holding tests miss lines of them.
 */
final readonly class Mutated
{
    public function __construct(public MutationResult $result, public MutantIds $flaky, public HeldMisses $misses)
    {
    }
}
