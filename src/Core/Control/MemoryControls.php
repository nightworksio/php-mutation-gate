<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;

use function sprintf;

/**
 * The unmutated control of each mutant out of memory (ADR-0004, decision
 * 9): the tests that judge it, those covering its lines as the coverage map
 * names them, and of a held unit only those that hold it, run with its file
 * served unmutated as its mutant was, under the same memory cap, allowed the
 * standard mutant limit of their time, its peak measured as every runner
 * measures one (see PeakLauncher). Memory triage weighs the mutant against
 * that control (see MemoryTriage): where it finishes under the cap, its peak
 * is the mutant's need. Where the tests fail unmutated too, nothing tells the
 * mutant from its original, and it is unjudged; where they run out of the cap
 * or of time too, or never run, the mutant is too heavy to judge, saying why.
 */
final readonly class MemoryControls implements ControlJudge
{
    /** Why a mutant out of memory is unjudged where its control fails. */
    public const string FAILS_UNMUTATED
        = 'Ran out of memory, but its tests fail as well with the file unmutated, served as its mutant was.';

    /** Why a mutant out of memory is too heavy to judge where its control runs out of the cap too. */
    public const string OUT_OF_MEMORY
        = 'Its tests run out of the same memory cap unmutated too, so nothing tells a runaway from tests that need it.';

    /** Why a mutant out of memory is too heavy to judge where its control runs out of time. */
    public const string RAN_OUT
        = 'Its tests run out of time unmutated, so nothing tells a runaway from tests that need the memory.';

    /** Why a mutant out of memory is too heavy to judge where its control never ran. */
    public const string UNRUN
        = 'No unmutated control of its tests ran, so nothing tells a runaway from tests that need the memory: %s.';

    private function __construct(private MutantControls $controls)
    {
    }

    /** The control of each of these mutants out of memory, its time limit within these bounds. */
    public static function of(Mutants $mutants, CoverageMap $map, HeldCovered $held, LimitBounds $bounds): self
    {
        $controls = [];

        foreach ($mutants as $mutant) {
            $location = $mutant->location();
            $tests = $held->judgingAmong(
                $location->file(),
                $map->testsCoveringSpan($location->file(), $location->start(), $location->last()),
            );
            $controls += $mutant->status() === MutantStatus::OutOfMemory
                && $mutant->limit() instanceof MemoryCap
                && count($tests) > 0
                ? [$mutant->id()->key() => Control::of(
                    $location->file(),
                    $tests,
                    MutantLimit::standard()->of(OwnTime::of($map, $tests), $bounds),
                )]
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
     * The mutants, each out of memory judged by what its control found: its
     * need the control's peak where it passed, or the cap where the peak was
     * not measured, unjudged where it failed, and too heavy to judge, saying
     * why, otherwise; or unjudged, the time budget having run out before its
     * control, where it is among those left.
     */
    public function applied(Mutants $mutants, ControlRuns $runs, Controls $left): Mutants
    {
        return $this->controls->applied($mutants, $runs, $left, $this);
    }

    /** A mutant out of memory as what its control found judges it. */
    public function judged(Mutant $mutant, ControlRun $run, Control $control): Mutant
    {
        $peak = $run->peak();
        $cap = $mutant->limit();
        $why = $run->why();

        return match ($run->end()) {
            ControlEnd::Passed => $peak instanceof MemoryCap || $cap instanceof MemoryCap
                ? $mutant->withUnmutatedNeed($peak instanceof MemoryCap ? $peak : $cap)
                : $mutant,
            ControlEnd::Failed => $mutant->unjudged(Reason::that(self::FAILS_UNMUTATED)),
            ControlEnd::OutOfMemory => $mutant->because(Reason::that(self::OUT_OF_MEMORY)),
            ControlEnd::RanOut => $mutant->because(Reason::that(self::RAN_OUT)),
            ControlEnd::Unrun => $mutant->because(
                Reason::that(sprintf(self::UNRUN, $why instanceof NotGiven ? ControlRuns::NOT_RUN : $why)),
            ),
        };
    }
}
