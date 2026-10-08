<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/**
 * What one invocation of a shard came to: its mutants, the survivors a
 * second run killed, and those proven equivalent.
 */
final readonly class Invoked
{
    public function __construct(public MutationResult $result, public MutantIds $flaky, public MutantIds $equivalent)
    {
    }
}
