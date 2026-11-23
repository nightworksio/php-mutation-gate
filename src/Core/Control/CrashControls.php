<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\UnfilteredRuns;

use function sprintf;

/**
 * The unmutated control of each mutant whose run, of it alone, crashed
 * (ADR-0333): the tests that judge it, those covering its lines as the
 * coverage map names them, and of a held unit only those that hold it, as
 * its own run selected them (see RunSelection), run with its file unmutated,
 * allowed the standard mutant limit of their time. Where they pass, the
 * mutant is what crashed its run, and it is errored. Where they fail
 * unmutated too, nothing tells the mutant from its original, and it is
 * unjudged; where they run out of time or of memory, or never run, it is
 * unjudged, saying why.
 *
 * @internal
 */
final readonly class CrashControls implements ControlJudge
{
    /** Why a mutant is errored where its run alone crashed and its control passes. */
    public const string CRASHED_ITS_RUN
        = 'Its run, of this mutant alone, crashed, and its tests pass with the file unmutated.';

    /** Why a mutant whose run alone crashed is unjudged where its control fails. */
    public const string FAILS_UNMUTATED
        = 'Its run, of this mutant alone, crashed, but its tests fail as well with the file unmutated.';

    /** Why a mutant whose run alone crashed is unjudged where its control does not finish. */
    public const string UNFINISHED
        = 'Its run, of this mutant alone, crashed, and its tests did not finish unmutated: %s.';

    private function __construct(private MutantControls $controls)
    {
    }

    /** The control of each of these mutants whose run alone crashed, its time limit within these bounds. */
    public static function of(
        Mutants $mutants,
        UnfilteredRuns $unfiltered,
        CoverageMap $map,
        HeldCovered $held,
        RunSelection $run,
        LimitBounds $bounds,
    ): self {
        $controls = [];

        foreach ($mutants as $mutant) {
            $reason = $mutant->reason();

            if (
                $mutant->status() !== MutantStatus::Unjudged
                || ! $reason instanceof Reason
                || $reason->outOfTime() !== OutOfTime::CrashedAlone
            ) {
                continue;
            }

            $tests = Control::judging($mutant, $map, $held, $run, $unfiltered->of($mutant->id()));
            $controls += count($tests->tests()) > 0
                ? [$mutant->id()->key() => Control::standard($mutant->location()->file(), $tests, $map, $bounds)]
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
     * The mutants, each whose run alone crashed judged by what its control
     * found; or unjudged, the time budget having run out before its control,
     * where it is among those left.
     */
    public function applied(Mutants $mutants, ControlRuns $runs, Controls $left): Mutants
    {
        return $this->controls->applied($mutants, $runs, $left, $this);
    }

    /** A mutant whose run alone crashed as what its control found judges it. */
    public function judged(Mutant $mutant, ControlRun $run, Control $control): Mutant
    {
        $why = $run->why();

        return match ($run->end()) {
            ControlEnd::Passed => $this->errored($mutant),
            ControlEnd::Failed => $mutant->unjudged(
                $run->unmutatedFailure($control->tests(), Reason::that(self::FAILS_UNMUTATED)),
            ),
            ControlEnd::OutOfMemory, ControlEnd::RanOut, ControlEnd::Unrun => $mutant->unjudged(Reason::that(sprintf(
                self::UNFINISHED,
                $why instanceof NotGiven ? $run->end()->value : $why,
            ))),
            ControlEnd::Halted => $mutant->unjudged(OutOfTime::BeforeControlling),
        };
    }

    /** The mutant, errored: its run crashed the tests, and no test is named for it. */
    private function errored(Mutant $mutant): Mutant
    {
        return Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            $mutant->location(),
            $mutant->mutation(),
            MutantStatus::Errored,
            $mutant->duration(),
        )->because(Reason::that(self::CRASHED_ITS_RUN));
    }
}
