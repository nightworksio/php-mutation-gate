<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/**
 * What one invocation of a shard came to: its mutants, the survivors a
 * second run killed, those proven equivalent, what static analysis's checks
 * of its survivors came to, the survivors they took up, and those a check
 * that never ran may yet kill.
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
        public MutantIds $unsettled,
    ) {
    }

    /** The survivors that doom no run: those proven equivalent, and those a check that never ran may yet kill. */
    public function passedOver(): MutantIds
    {
        return $this->equivalent->and($this->unsettled);
    }
}
