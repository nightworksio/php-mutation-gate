<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

/**
 * Which tests cover each mutant of a verdict and what each did with it in
 * place: the run's coverage map for run and proved units, whose keys hold
 * each covered line's tests, and each proof's own tests for carried ones;
 * the killers each mutant's record names; and the names the runner gives its
 * tests (ADR-0014, decisions 9 to 11).
 */
final readonly class KillMatrix
{
    /**
     * @param array<string, TestIds> $carried the covering tests of each carried mutant, by its id
     * @param array<string, true>    $moved   the carried mutants whose coverage moved since their proof, by id
     */
    private function __construct(
        private MatrixKind $kind,
        private CoverageMap $coverage,
        private array $carried,
        private array $moved,
        private TestNames $names,
    ) {
    }

    /** A matrix of first killers with no coverage, which knows only the killers each mutant's record names. */
    public static function none(): self
    {
        return new self(MatrixKind::FirstKiller, CoverageMap::empty(), [], [], TestNames::none());
    }

    /** A matrix of this kind over the run's coverage map. */
    public static function of(MatrixKind $kind, CoverageMap $coverage): self
    {
        return new self($kind, $coverage, [], [], TestNames::none());
    }

    /** This matrix, with a carried mutant covered by the tests its proof names. */
    public function carried(MutantId $mutant, TestIds $covering): self
    {
        $carried = $this->carried;
        $carried[$mutant->value()] = $covering;

        return clone($this, ['carried' => $carried]);
    }

    /**
     * This matrix, with a carried mutant whose unit's coverage moved since
     * its proof, so each of its cells is unknown.
     */
    public function moved(MutantId $mutant): self
    {
        $moved = $this->moved;
        $moved[$mutant->value()] = true;

        return clone($this, ['moved' => $moved]);
    }

    /** This matrix, with the names the runner gives its tests. */
    public function named(TestNames $names): self
    {
        return clone($this, ['names' => $names]);
    }

    public function kind(): MatrixKind
    {
        return $this->kind;
    }

    public function names(): TestNames
    {
        return $this->names;
    }

    /** How long a test runs on its own, as the coverage run measured it. */
    public function secondsOf(TestId $test): Seconds|Unmeasured
    {
        return $this->coverage->durationOf($test);
    }

    /** Every test that covers the mutant, and every test its record names as a killer. */
    public function coveredBy(JudgedMutant $judged): TestIds
    {
        $mutant = $judged->mutant();
        $id = $mutant->id()->value();
        $covering = array_key_exists($id, $this->carried) ? $this->carried[$id] : $this->onLines($judged);

        foreach ($mutant->killers() as $killer) {
            $covering = $covering->with($killer);
        }

        return $covering;
    }

    /** What is known of this covering test with the mutant in place. */
    public function outcome(JudgedMutant $judged, TestId $test): Outcome
    {
        $mutant = $judged->mutant();
        $unknown = array_key_exists($mutant->id()->value(), $this->moved)
            || $judged->judgement() === MutantJudgement::Flaky
            || $mutant->status() === MutantStatus::TimedOut;

        return $unknown ? Outcome::Unknown : $this->reported($judged, $test);
    }

    /** The outcome the mutant's own record gives the test. */
    private function reported(JudgedMutant $judged, TestId $test): Outcome
    {
        $killers = $judged->mutant()->killers();
        $afterTheKiller = $this->kind === MatrixKind::Full ? Outcome::Passed : Outcome::NotRun;

        return match ($judged->mutant()->status()) {
            MutantStatus::Survived => Outcome::Passed,
            MutantStatus::Killed, MutantStatus::Errored => match (true) {
                count($killers) === 0 => Outcome::Unknown,
                $killers->has($test) => Outcome::Killed,
                default => $afterTheKiller,
            },
            MutantStatus::TimedOut => Outcome::Unknown,
            MutantStatus::Uncovered,
            MutantStatus::Unjudged,
            MutantStatus::IgnoredByMarker,
            MutantStatus::Skipped => Outcome::NotRun,
        };
    }

    /** The tests the coverage map holds for any line the mutant spans. */
    private function onLines(JudgedMutant $judged): TestIds
    {
        $location = $judged->mutant()->location();
        $end = $location->end();
        $last = $end instanceof Line ? $end->number() : $location->start()->number();
        $covering = TestIds::none();

        for ($line = $location->start()->number(); $line <= $last; ++$line) {
            foreach ($this->coverage->testsCovering($location->file(), Line::of($line)) as $test) {
                $covering = $covering->with($test);
            }
        }

        return $covering;
    }
}
