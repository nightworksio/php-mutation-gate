<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/** What one invocation of a shard came to: its mutants, and the survivors a second run killed. */
final readonly class Invoked
{
    public function __construct(public MutationResult $result, public MutantIds $flaky)
    {
    }
}
