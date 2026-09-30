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
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
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
        private NotFull $notFull,
    ) {
    }

    /** A matrix of first killers with no coverage, which knows only the killers each mutant's record names. */
    public static function none(): self
    {
        return self::of(MatrixKind::FirstKiller, CoverageMap::empty());
    }

    /** A matrix of this kind over the run's coverage map. */
    public static function of(MatrixKind $kind, CoverageMap $coverage): self
    {
        return new self($kind, $coverage, [], [], TestNames::none(), NotFull::FirstKillers);
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

    /** This matrix, whose runner cannot record every killer, and why. */
    public function cannotBeFull(NotFull $why): self
    {
        return clone($this, ['notFull' => $why]);
    }

    public function kind(): MatrixKind
    {
        return $this->kind;
    }

    /** Why a matrix of first killers is not full: the run did not ask, or its runner cannot. */
    public function whyNotFull(): NotFull
    {
        return $this->notFull;
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
    public function coveredBy(JudgedMutant|JudgedKill $judged): TestIds
    {
        $mutant = $judged->mutant();
        $id = $mutant->id()->value();
        $covering = array_key_exists($id, $this->carried) ? $this->carried[$id] : $this->onLines($judged);

        foreach ($mutant->killers() as $killer) {
            $covering = $covering->with($killer);
        }

        return $covering;
    }

    /** The coverage the run read, for what each test covers line by line. */
    public function coverage(): CoverageMap
    {
        return $this->coverage;
    }

    /**
     * Whether this test judged the mutant: it is among the tests that judge
     * it, such as a held unit's group, or nobody named those tests.
     */
    public function judges(JudgedMutant|JudgedKill $judged, TestId $test): bool
    {
        return count($judged->tests()) === 0 || $judged->tests()->has($test);
    }

    /** What is known of this covering test with the mutant in place; a test that did not judge it never ran with it. */
    public function outcome(JudgedMutant|JudgedKill $judged, TestId $test): Outcome
    {
        $mutant = $judged->mutant();
        $unknown = array_key_exists($mutant->id()->value(), $this->moved)
            || $judged->judgement() === MutantJudgement::Flaky
            || $mutant->status() === MutantStatus::TimedOut;

        return match (true) {
            ! $this->judges($judged, $test) => Outcome::NotRun,
            $unknown => Outcome::Unknown,
            default => $this->reported($judged, $test),
        };
    }

    /** The outcome the mutant's own record gives the test. */
    private function reported(JudgedMutant|JudgedKill $judged, TestId $test): Outcome
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
    private function onLines(JudgedMutant|JudgedKill $judged): TestIds
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
