<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * A kill a ledger proved, as a verdict counts it: killed, by definition, and
 * on a changed line or not. It is never a survivor, so nothing that lists
 * survivors meets one.
 */
final readonly class JudgedKill
{
    private function __construct(private ProvedKill $kill, private bool $onChangedLine)
    {
    }

    public static function of(ProvedKill $kill): self
    {
        return new self($kill, onChangedLine: false);
    }

    /** This kill, marked on a changed line when the line it starts on is one the reach holds for its file. */
    public function within(Reach $reach): self
    {
        $location = $this->kill->location();

        return new self($this->kill, $reach->changedLines($location->file())->has($location->start()));
    }

    public function mutant(): ProvedKill
    {
        return $this->kill;
    }

    /** Always killed: a ledger keeps a mutant as a kill only when a run killed it. */
    public function judgement(): MutantJudgement
    {
        return MutantJudgement::Killed;
    }

    public function isOnChangedLine(): bool
    {
        return $this->onChangedLine;
    }

    /** The tests that judged it, which a ledger does not keep: none. Its killers say who killed it. */
    public function tests(): TestIds
    {
        return TestIds::none();
    }

    /** What its tests miss: nothing, since they killed it. */
    public function hint(): Hint
    {
        return Hint::killed();
    }

    /** The one command that runs it again, on any machine with the same code. */
    public function reproduce(): string
    {
        return sprintf(JudgedMutant::REPRODUCE, $this->kill->id()->value());
    }

    /** The one command that explains it, running nothing. */
    public function explain(): string
    {
        return sprintf(JudgedMutant::EXPLAIN, $this->kill->id()->value());
    }
}
