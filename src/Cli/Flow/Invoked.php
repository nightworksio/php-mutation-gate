<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedIds;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/**
 * What one invocation of a shard came to: its mutants, the survivors a
 * second run killed, those proven equivalent, what static analysis's checks
 * of its survivors came to, the survivors they took up, and those they left
 * unchecked, by why.
 *
 * @internal
 */
final readonly class Invoked
{
    public function __construct(
        public MutationResult $result,
        public MutantIds $flaky,
        public MutantIds $equivalent,
        public SurvivorChecks $checks,
        public MutantIds $checked,
        public UncheckedIds $unchecked,
    ) {
    }

    /** The survivors that doom no run: those proven equivalent, and those left unchecked for a reason it waits on. */
    public function passedOver(Unchecked ...$waits): MutantIds
    {
        return $this->equivalent->and($this->unchecked->for(...$waits));
    }
}
