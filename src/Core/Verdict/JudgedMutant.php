<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Cluster\Membership;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * One mutant, what the gate made of it, the tests that judged it, what they
 * miss, whether it sits on a line the change added or modified, and the
 * cluster of survivors it shares a cause with.
 */
final readonly class JudgedMutant
{
    /** The command that runs one mutant again, which every report prints beside it (ADR-0009, decision 6). */
    public const string REPRODUCE = 'vendor/bin/mutation-gate reproduce %s';

    /** The command that explains one mutant or cluster without running anything (ADR-0014, decision 12). */
    public const string EXPLAIN = 'vendor/bin/mutation-gate explain %s';

    private function __construct(
        private Mutant $mutant,
        private MutantJudgement $judgement,
        private bool $onChangedLine,
        private TestIds $tests,
        private Hint|Missing $hint,
        private Membership|Unclustered $cluster,
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
            cluster: Unclustered::mutant(),
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

    /** This mutant, in a cluster of survivors with one cause (ADR-0022, decision 15). */
    public function inCluster(Membership $cluster): self
    {
        return clone($this, ['cluster' => $cluster]);
    }

    /**
     * This mutant, proven equivalent where it survived: its compiled code is
     * the original's. A mutant judged otherwise stays as it was, so a kill
     * never becomes a pass (ADR-0013, decision 10).
     */
    public function provenEquivalent(): self
    {
        return $this->judgement === MutantJudgement::Survived
            ? clone($this, ['judgement' => MutantJudgement::Equivalent])
            : $this;
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

    /** The cluster it is in; none for a mutant that shares its cause with no other survivor. */
    public function cluster(): Membership|Unclustered
    {
        return $this->cluster;
    }

    /** The one command that runs it again, on any machine with the same code. */
    public function reproduce(): string
    {
        return sprintf(self::REPRODUCE, $this->mutant->id()->value());
    }

    /** The command that explains it from what the ledgers and the last run hold, running nothing. */
    public function explain(): string
    {
        return sprintf(self::EXPLAIN, $this->mutant->id()->value());
    }
}
