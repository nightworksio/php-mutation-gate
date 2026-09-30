<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\NeverProved;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;

/**
 * What of a unit's newest result still stands for the code on disk, for a
 * unit a time budget ran out before (ADR-0008, decision 1), judged against
 * the digests of this run's inputs, its base, the names of its tests and its
 * coverage map. A result stands for the unit only where its source and what
 * decides its mutant set are unchanged; then each of its mutants stands, or
 * is unjudged, as {@see Carry} says.
 */
final readonly class Carrying
{
    private function __construct(
        private Digests|Undigested $now,
        private Digest $base,
        private TestNames|CannotJudge $names,
        private CoverageMap|CannotJudge $map,
    ) {
    }

    /** Carrying judged against this run's digests and base, its tests' names and its coverage map. */
    public static function against(
        Digests|Undigested $now,
        Digest $base,
        TestNames|CannotJudge $names,
        CoverageMap|CannotJudge $map,
    ): self {
        return new self($now, $base, $names, $map);
    }

    /** The newest result, where its mutant set is the one the code on disk makes; or why it is not. */
    public function counted(Proof|NeverProved $newest): Proof|Uncounted
    {
        $inputs = $newest instanceof Proof ? $newest->inputs() : Undigested::proof();

        return match (true) {
            ! $newest instanceof Proof => Uncounted::NoResult,
            ! $inputs instanceof Inputs || ! $this->now instanceof Digests => Uncounted::NoDigests,
            default => $this->comparing($newest, $inputs, $this->now),
        };
    }

    /**
     * Whether a mutant of a result that stands for its unit stands too, or is
     * unjudged, and why. A timeout is unjudged, since triage can count it as
     * a kill (ADR-0008); a skipped mutant stands, since nothing counts it as
     * one. A kill by static analysis carries like a kill with no killing test
     * file: the analyser's answer is fixed by what the base covers.
     */
    public function carry(Proof $proof, Mutant|ProvedKill $mutant): Carry
    {
        return match ($mutant->status()) {
            MutantStatus::Killed => $this->kill($proof, $mutant),
            MutantStatus::KilledByStaticAnalysis => $this->rejection($proof),
            MutantStatus::Errored, MutantStatus::TimedOut => Carry::KillerUnknown,
            MutantStatus::Uncovered => $this->uncovered($mutant),
            MutantStatus::Survived,
            MutantStatus::Unjudged,
            MutantStatus::IgnoredByMarker,
            MutantStatus::Skipped => Carry::Stands,
        };
    }

    private function comparing(Proof $proof, Inputs $inputs, Digests $now): Proof|Uncounted
    {
        $source = $now->sourceOf($proof->unit());

        return match (true) {
            ! $source instanceof Digest || $source->value() !== $inputs->source()->value() => Uncounted::SourceChanged,
            $now->mutation()->value() !== $inputs->mutation()->value() => Uncounted::MutationChanged,
            default => $proof,
        };
    }

    /** A kill stands at the same base, every test that killed it known and unchanged, with what it reads. */
    private function kill(Proof $proof, Mutant|ProvedKill $kill): Carry
    {
        return match (true) {
            $proof->run()->base()->value() !== $this->base->value() => Carry::OtherBase,
            count($kill->killers()) === 0 || ! $this->names instanceof TestNames => Carry::KillerUnknown,
            default => $this->killers($proof->inputs(), $kill, $this->names),
        };
    }

    /** A kill by static analysis stands at the same base, and no test file killed it. */
    private function rejection(Proof $proof): Carry
    {
        return $proof->run()->base()->value() === $this->base->value() ? Carry::Stands : Carry::OtherBase;
    }

    /** Whether every test that killed a mutant is named, and reads what it read when it killed it. */
    private function killers(Inputs|Undigested $inputs, Mutant|ProvedKill $kill, TestNames $names): Carry
    {
        $carry = Carry::Stands;

        foreach ($kill->killers() as $test) {
            $named = $names->testOf($test);
            $carry = match (true) {
                $carry !== Carry::Stands => $carry,
                ! $named instanceof TestName => Carry::KillerUnknown,
                default => $this->unchanged($inputs, $named) ? Carry::Stands : Carry::KillerChanged,
            };
        }

        return $carry;
    }

    /** Whether a killing test file reads now what it read when the result was established. */
    private function unchanged(Inputs|Undigested $inputs, TestName $test): bool
    {
        $then = $inputs instanceof Inputs ? $inputs->testDigest($test->file()) : Undigested::proof();
        $now = $this->now instanceof Digests ? $this->now->testOf($test->file()) : Undigested::proof();

        return $then instanceof Digest && $now instanceof Digest && $then->value() === $now->value();
    }

    private function uncovered(Mutant|ProvedKill $mutant): Carry
    {
        $location = $mutant->location();

        return match (true) {
            ! $this->map instanceof CoverageMap => Carry::CoverageUnknown,
            count($this->map->testsCovering($location->file(), $location->start())) > 0 => Carry::NowCovered,
            default => Carry::Stands,
        };
    }
}
