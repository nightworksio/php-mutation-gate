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
 *
 * @internal
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

    /**
     * What the shard came to, with another group of its units' runs after these (ADR-0074): their mutants, the
     * survivors a second run killed, the units left unjudged, the checks and the warnings join these, and a doom
     * either reached stands. The held units' checks are the shard's, which every group shares.
     */
    public function and(self $later): self
    {
        return new self(
            $this->result->and($later->result),
            $this->flaky->and($later->flaky),
            $this->held,
            $this->unjudged->and($later->unjudged),
            $this->checks->plus($later->checks),
            $this->doomed instanceof Doomed ? $this->doomed : $later->doomed,
        );
    }

    /** The same, with these units left unjudged, as the groups after a doom are. */
    public function leaving(Units $units): self
    {
        return new self(
            $this->result,
            $this->flaky,
            $this->held,
            $this->unjudged->and($units),
            $this->checks,
            $this->doomed,
        );
    }
}
