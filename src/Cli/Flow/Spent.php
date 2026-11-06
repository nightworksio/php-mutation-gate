<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doom;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * What a shard's invocations have come to so far: every mutant, the
 * survivors a second run killed, the mutants skipped without a record, the
 * units its budget ran out before or its doom left, what the runner warned
 * of, the evidence of its kills, the survivor that made its run certain to
 * fail, where one did, and what static analysis's checks of the survivors
 * of its chunks came to, with the survivors they took up (ADR-0008).
 *
 * @internal
 */
final readonly class Spent
{
    private function __construct(
        public Mutants $mutants,
        public MutantIds $flaky,
        public int $skipped,
        public Units $unjudged,
        public Warnings $warnings,
        public Evidences $evidence,
        public Doomed|Undoomed $doomed,
        public SurvivorChecks $checks,
        public MutantIds $checked,
    ) {
    }

    /** Nothing spent yet. */
    public static function none(): self
    {
        return new self(
            Mutants::none(),
            MutantIds::none(),
            0,
            Units::none(),
            Warnings::none(),
            Evidences::none(),
            Undoomed::run(),
            SurvivorChecks::none(),
            MutantIds::none(),
        );
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
            $this->evidence->and($invoked->result->evidence()),
            $this->doomed,
            $this->checks->plus($invoked->checks),
            $this->checked->and($invoked->checked),
        );
    }

    /** What was spent, and these units left unjudged. */
    public function leaving(Units $units): self
    {
        $unjudged = $this->unjudged;

        foreach ($units as $unit) {
            $unjudged = $unjudged->with($unit);
        }

        return new self(
            $this->mutants,
            $this->flaky,
            $this->skipped,
            $unjudged,
            $this->warnings,
            $this->evidence,
            $this->doomed,
            $this->checks,
            $this->checked,
        );
    }

    /**
     * What was spent, with the first survivor of this batch that makes the run certain to fail, where none did
     * before, found this far into the shard's mutating, the shard stopping there or judging on (ADR-0008,
     * ADR-0103). Those flaky or proven equivalent never do, nor those a static check that never ran may yet kill.
     */
    public function watched(Doom|Undoomed $doom, Units $batch, Invoked $invoked, Seconds $after, bool $stops): self
    {
        $doomed = $doom instanceof Doom && ! $this->doomed instanceof Doomed
            ? $doom->first($batch, $invoked->result->mutants(), $invoked->flaky, $invoked->passedOver())
            : Undoomed::run();

        return $doomed instanceof Doomed ? $this->doomedBy($doomed->found($after, $stops)) : $this;
    }

    /** What was spent, stopped on the survivor that made the run certain to fail (ADR-0008). */
    public function doomedBy(Doomed $doomed): self
    {
        return new self(
            $this->mutants,
            $this->flaky,
            $this->skipped,
            $this->unjudged,
            $this->warnings,
            $this->evidence,
            $doomed,
            $this->checks,
            $this->checked,
        );
    }
}
