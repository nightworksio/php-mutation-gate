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
 * of its survivors came to, the survivors they took up, those they left
 * unchecked, by why, and those judged by one part of the test files alone
 * (ADR-0117).
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
        public MutantIds $parted,
    ) {
    }

    /**
     * The survivors that doom no run: those proven equivalent, those left
     * unchecked for a reason it waits on, and those judged by one part of the
     * test files alone, which another part may yet kill (ADR-0117).
     */
    public function passedOver(Unchecked ...$waits): MutantIds
    {
        return $this->equivalent->and($this->unchecked->for(...$waits))->and($this->parted);
    }
}
