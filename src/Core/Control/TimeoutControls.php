<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * The unmutated control of each timed-out mutant (ADR-0008, decision 2):
 * the tests that judge it, those covering its lines as the coverage map
 * names them, and of a held unit only those that hold it, run with its file
 * served unmutated as its mutant was, allowed the limit its run was. A
 * mutant whose own run already ran its tests unmutated, as a trial does,
 * has none. The map's time of the tests only set the limit; what triage
 * weighs the timeout against is the control's (see TimeoutTriage). Where
 * the tests fail unmutated too, nothing tells the mutant from its original,
 * and it is unjudged; where they run out of the limit too, or never run,
 * the mutant is too slow to judge, and its reason says why.
 */
final readonly class TimeoutControls implements ControlJudge
{
    /** Why a timeout is unjudged where its control fails. */
    public const string FAILS_UNMUTATED
        = 'Ran out of time, but its tests fail as well with the file unmutated, served as its mutant was.';

    /** Why a timeout is unjudged where its control runs out of the memory cap. */
    public const string OUT_OF_MEMORY
        = 'Ran out of time, but its tests run out of the memory cap with the file unmutated too.';

    /** Why a timeout is too slow to judge where its control runs out of the limit too. */
    public const string RAN_OUT
        = 'Its tests run out of the same limit with the file unmutated too, so nothing tells a hang from slow tests.';

    /** Why a timeout is too slow to judge where its control never ran. */
    public const string UNRUN = 'No unmutated control of its tests ran, so nothing tells a hang from slow tests: %s.';

    private function __construct(private MutantControls $controls)
    {
    }

    /** The control of each of these mutants that needs one. */
    public static function of(Mutants $mutants, CoverageMap $map, HeldCovered $held): self
    {
        $controls = [];

        foreach ($mutants as $mutant) {
            $limit = $mutant->limit();
            $location = $mutant->location();
            $tests = $held->judgingAmong(
                $location->file(),
                $map->testsCoveringSpan($location->file(), $location->start(), $location->last()),
            );
            $controls += $mutant->status() === MutantStatus::TimedOut
                && $limit instanceof Seconds
                && ! $mutant->unmutatedNeed() instanceof Seconds
                && count($tests) > 0
                ? [$mutant->id()->key() => Control::of($location->file(), $tests, $limit)]
                : [];
        }

        return new self(MutantControls::of($controls));
    }

    /** Every control asked for, each once. */
    public function asked(): Controls
    {
        return $this->controls->asked();
    }

    /**
     * The mutants, each timeout judged by what its control found: its tests'
     * time the control's where they passed, or its limit where the runner did
     * not time them, unjudged where they failed, and too slow to judge,
     * saying why, otherwise; or unjudged, the time budget having run out
     * before its control, where it is among those left.
     */
    public function applied(Mutants $mutants, ControlRuns $runs, Controls $left): Mutants
    {
        return $this->controls->applied($mutants, $runs, $left, $this);
    }

    /** A timeout as what its control found judges it. */
    public function judged(Mutant $mutant, ControlRun $run, Control $control): Mutant
    {
        $took = $run->took();
        $why = $run->why();

        return match ($run->end()) {
            ControlEnd::Passed => $mutant->withUnmutatedNeed($took instanceof Seconds ? $took : $control->limit()),
            ControlEnd::Failed => $mutant->unjudged(Reason::that(self::FAILS_UNMUTATED)),
            ControlEnd::OutOfMemory => $mutant->unjudged(Reason::that(self::OUT_OF_MEMORY)),
            ControlEnd::RanOut => $mutant->because(Reason::that(self::RAN_OUT)),
            ControlEnd::Unrun => $mutant->because(
                Reason::that(sprintf(self::UNRUN, $why instanceof NotGiven ? ControlRuns::NOT_RUN : $why)),
            ),
        };
    }
}
