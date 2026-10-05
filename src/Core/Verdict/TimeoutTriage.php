<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * Timeout triage (ADR-0008): a timed-out mutant whose judging tests take
 * under half its limit on their own broke something, and is killed by
 * timeout; any other, and one where either time is unknown, is too slow to
 * judge. Under `timeouts.mode: unjudged` every timeout is too slow to judge.
 */
final readonly class TimeoutTriage
{
    /** A limit is halved to tell a detection from the clock running out. */
    private const int HALVES = 2;

    private function __construct(private TimeoutMode $mode)
    {
    }

    public static function under(TimeoutMode $mode): self
    {
        return new self($mode);
    }

    /**
     * The mutants, each one whose time ran out with the seconds the tests
     * covering its line take on their own, as a coverage map measured them;
     * where one of them was not measured, its time stays unknown.
     */
    public static function timed(Mutants $mutants, CoverageMap $map): Mutants
    {
        $timed = [];

        foreach ($mutants as $mutant) {
            $time = $mutant->status()->ranOutOfTime() ? self::judgingTimeOf($mutant, $map) : Unmeasured::duration();
            $timed[] = $time instanceof Seconds ? $mutant->withUnmutatedNeed($time) : $mutant;
        }

        return Mutants::of(...$timed);
    }

    /** What a mutant comes to: as its status reports it, and a timeout as triage judges it. */
    public function judged(Mutant $mutant): MutantJudgement
    {
        $limit = $mutant->limit();
        $time = $mutant->unmutatedNeed();

        return $mutant->status() === MutantStatus::TimedOut
            && $this->mode === TimeoutMode::Confirm
            && $limit instanceof Seconds
            && $time instanceof Seconds
            && $time->seconds() * self::HALVES < $limit->seconds()
            ? MutantJudgement::KilledByTimeout
            : MutantJudgement::reported($mutant->status());
    }

    private static function judgingTimeOf(Mutant $mutant, CoverageMap $map): Seconds|Unmeasured
    {
        $location = $mutant->location();

        return OwnTime::of($map, $map->testsCoveringSpan($location->file(), $location->start(), $location->last()));
    }
}
