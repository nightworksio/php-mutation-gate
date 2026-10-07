<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * What a shard's invocations have come to so far: every mutant, the
 * survivors a second run killed, the mutants skipped without a record, the
 * units its budget ran out before, and what the runner warned of.
 */
final readonly class Spent
{
    private function __construct(
        public Mutants $mutants,
        public MutantIds $flaky,
        public int $skipped,
        public Units $unjudged,
        public Warnings $warnings,
    ) {
    }

    /** Nothing spent yet. */
    public static function none(): self
    {
        return new self(Mutants::none(), MutantIds::none(), 0, Units::none(), Warnings::none());
    }

    /** What was spent, and one invocation more. */
    public function after(Invoked $invoked): self
    {
        return new self(
            Mutants::of(...$this->mutants, ...$invoked->result->mutants()),
            $this->flaky->and($invoked->flaky),
            $this->skipped + $invoked->result->skipped(),
            $this->unjudged,
            $this->warnings->and($invoked->result->warnings()),
        );
    }

    /** What was spent, and these units left unjudged. */
    public function leaving(Units $units): self
    {
        $unjudged = $this->unjudged;

        foreach ($units as $unit) {
            $unjudged = $unjudged->with($unit);
        }

        return new self($this->mutants, $this->flaky, $this->skipped, $unjudged, $this->warnings);
    }
}
