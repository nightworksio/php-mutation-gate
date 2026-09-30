<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * One mutant, what the gate made of it, the tests that judged it, what they
 * miss, and whether it sits on a line the change added or modified.
 */
final readonly class JudgedMutant
{
    /** The command that runs one mutant again, which every report prints beside it (ADR-0009, decision 6). */
    private const string REPRODUCE = 'vendor/bin/mutation-gate reproduce %s';

    private function __construct(
        private Mutant $mutant,
        private MutantJudgement $judgement,
        private bool $onChangedLine,
        private TestIds $tests,
        private Hint|Missing $hint,
    ) {
    }

    public static function of(Mutant $mutant, MutantJudgement $judgement): self
    {
        return new self(
            $mutant,
            $judgement,
            onChangedLine: false,
            tests: TestIds::none(),
            hint: Missing::at($mutant->location()->file()),
        );
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

    /** This mutant, with what its tests miss, as `Hint::for()` reads it from its file. */
    public function hinted(Hint $hint): self
    {
        return clone($this, ['hint' => $hint]);
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

    /** What its tests miss: as `hinted()` gave it, or as its diff and judging tests alone say where it was not read. */
    public function hint(): Hint
    {
        return $this->hint instanceof Hint
            ? $this->hint
            : Hint::for($this->mutant, $this->judgement, $this->tests, $this->hint);
    }

    /** The one command that runs it again, on any machine with the same code. */
    public function reproduce(): string
    {
        return sprintf(self::REPRODUCE, $this->mutant->id()->value());
    }
}
