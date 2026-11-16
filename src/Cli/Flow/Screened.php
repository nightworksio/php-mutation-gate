<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

/**
 * What screening an invocation's survivors came to (ADR-0008, ADR-0013,
 * ADR-0020, ADR-0108): its mutants, those proven equivalent, what static
 * analysis's checks came to and the survivors they took up, those a check
 * that never ran may yet kill, and the survivors a second run killed. A
 * survivor a control leaves, as the parts of a heavy mutant do, is screened
 * the same way after it.
 *
 * @internal
 */
final readonly class Screened
{
    private function __construct(
        public Mutants $mutants,
        public MutantIds $flaky,
        public MutantIds $equivalent,
        public SurvivorChecks $checks,
        public MutantIds $checked,
        public UncheckedIds $unchecked,
    ) {
    }

    public static function of(Checked $checked, Confirmed $confirmed, MutantIds $equivalent): self
    {
        return new self(
            $confirmed->mutants,
            $confirmed->flaky,
            $equivalent,
            $checked->checks,
            $checked->examinedIds(),
            $checked->unchecked,
        );
    }

    /** The ids of these mutants, as a control left them, but those that survive there and did not here. */
    public function settledIn(Mutants $controlled): MutantIds
    {
        $late = $this->survivorsOf($controlled)->without($this->survivorsOf($this->mutants));

        return MutantIds::of(...array_map(static fn(Mutant $mutant): MutantId => $mutant->id(), [...$controlled]))
            ->without($late);
    }

    /** This, its mutants as a control left them. */
    public function controlled(Mutants $mutants): self
    {
        return new self($mutants, $this->flaky, $this->equivalent, $this->checks, $this->checked, $this->unchecked);
    }

    /** This, then what screening the survivors a control left came to. */
    public function then(self $late): self
    {
        return new self(
            $late->mutants,
            $this->flaky->and($late->flaky),
            $this->equivalent->and($late->equivalent),
            $this->checks->plus($late->checks),
            $this->checked->and($late->checked),
            $this->unchecked->plus($late->unchecked),
        );
    }

    private function survivorsOf(Mutants $mutants): MutantIds
    {
        $survivors = [];

        foreach ($mutants as $mutant) {
            $survivors = $mutant->status() === MutantStatus::Survived ? [...$survivors, $mutant->id()] : $survivors;
        }

        return MutantIds::of(...$survivors);
    }
}
