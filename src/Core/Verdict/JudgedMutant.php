<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * One mutant, what the gate made of it, the tests that judged it, and whether
 * it sits on a line the change added or modified.
 */
final readonly class JudgedMutant
{
    private function __construct(
        private Mutant $mutant,
        private MutantJudgement $judgement,
        private bool $onChangedLine,
        private TestIds $tests,
    ) {
    }

    public static function of(Mutant $mutant, MutantJudgement $judgement): self
    {
        return new self($mutant, $judgement, onChangedLine: false, tests: TestIds::none());
    }

    /** This mutant, marked on a changed line when the line it starts on is one the reach holds for its file. */
    public function within(Reach $reach): self
    {
        $location = $this->mutant->location();

        return clone($this, ['onChangedLine' => $reach->changedLines($location->file())->has($location->start())]);
    }

    /** This mutant, judged by these tests: those covering its line, or the group of the held unit it is in. */
    public function judgedBy(TestIds $tests): self
    {
        return clone($this, ['tests' => $tests]);
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

    /** The tests that judged it; none for an uncovered mutant, or where nobody said. */
    public function tests(): TestIds
    {
        return $this->tests;
    }
}
