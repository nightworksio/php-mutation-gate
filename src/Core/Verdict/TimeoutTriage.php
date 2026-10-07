<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * Timeout triage (ADR-0008): a timed-out mutant whose limit allowed its
 * judging tests the standard limit's multiple of their own time broke
 * something, and is killed by timeout; any other, and one where either time
 * is unknown, is too slow to judge. Under `timeouts.mode: unjudged` every timeout is too slow to judge.
 */
final readonly class TimeoutTriage
{
    private function __construct(private TimeoutMode $mode)
    {
    }

    public static function under(TimeoutMode $mode): self
    {
        return new self($mode);
    }

    /**
     * The mutants, each one whose time ran out with the seconds its judging
     * tests take on their own, as a coverage map measured them: the tests
     * covering its lines, and of a held unit only those that hold it, the
     * tests its run selected. Where one of them was not measured, its time
     * stays unknown. A mutant whose run already timed its own tests on their
     * own, unmutated, as a trial does, keeps that time.
     */
    public static function timed(Mutants $mutants, CoverageMap $map, HeldCovered $held): Mutants
    {
        $timed = [];

        foreach ($mutants as $mutant) {
            $time = $mutant->status()->ranOutOfTime() && ! $mutant->unmutatedNeed() instanceof Seconds
                ? self::judgingTimeOf($mutant, $map, $held)
                : Unmeasured::duration();
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
            && MutantLimit::standard()->allowed($time, $limit)
            ? MutantJudgement::KilledByTimeout
            : MutantJudgement::reported($mutant->status());
    }

    private static function judgingTimeOf(Mutant $mutant, CoverageMap $map, HeldCovered $held): Seconds|Unmeasured
    {
        $location = $mutant->location();
        $covering = $map->testsCoveringSpan($location->file(), $location->start(), $location->last());

        return OwnTime::of($map, $held->judgingAmong($location->file(), $covering));
    }
}
