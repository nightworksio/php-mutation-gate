<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Reach\Reach;

/** One mutant, what the gate made of it, and whether it sits on a line the change added or modified. */
final readonly class JudgedMutant
{
    private function __construct(
        private Mutant $mutant,
        private MutantJudgement $judgement,
        private bool $onChangedLine,
    ) {
    }

    public static function of(Mutant $mutant, MutantJudgement $judgement): self
    {
        return new self($mutant, $judgement, onChangedLine: false);
    }

    /** This mutant, marked on a changed line when the line it starts on is one the reach holds for its file. */
    public function within(Reach $reach): self
    {
        $location = $this->mutant->location();

        return new self(
            $this->mutant,
            $this->judgement,
            $reach->changedLines($location->file())->has($location->start()),
        );
    }

    public function mutant(): Mutant
    {
        return $this->mutant;
    }

    public function judgement(): MutantJudgement
    {
        return $this->judgement;
    }

    public function isOnChangedLine(): bool
    {
        return $this->onChangedLine;
    }
}
