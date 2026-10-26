<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unevidenced;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;

use function sprintf;

/**
 * The unmutated control of each kill no test is named for and whose ending
 * does not vouch for it (ADR-0014): the tests that judge it, those covering
 * its lines as the coverage map names them, and of a held unit only those
 * that hold it, run with its file served unmutated as its mutant was,
 * allowed the standard mutant limit of their time. Where they pass, the
 * mutant's run failed and its original's does not, so the kill stands, its
 * killer unknown. Where they fail, run out of the cap or the limit, or never
 * run, nothing tells the mutant from its original, and it is unjudged,
 * saying why.
 */
final readonly class UnnamedKillControls implements ControlJudge
{
    /** Why an unnamed kill is unjudged where its control fails. */
    public const string FAILS_UNMUTATED
        = 'Killed, though no test was named, and its tests fail with the file unmutated too, served as its mutant was.';

    /** Why an unnamed kill is unjudged where its control runs out of the memory cap. */
    public const string OUT_OF_MEMORY
        = 'Killed, though no test was named, and its tests run out of the memory cap with the file unmutated too.';

    /** Why an unnamed kill is unjudged where its control runs out of its limit. */
    public const string RAN_OUT
        = 'Killed, though no test was named, and its tests run out of their limit with the file unmutated too.';

    /** Why an unnamed kill is unjudged where its control never ran. */
    public const string UNRUN
        = 'Killed, though no test was named, and no unmutated control of its tests ran to vouch for it: %s.';

    private function __construct(private MutantControls $controls)
    {
    }

    /** The control of each of these mutants whose kill only a control can vouch for, its limit within these bounds. */
    public static function of(
        Mutants $mutants,
        Evidences $evidence,
        CoverageMap $map,
        HeldCovered $held,
        LimitBounds $bounds,
    ): self {
        $controls = [];

        foreach ($mutants as $mutant) {
            $location = $mutant->location();
            $tests = $held->judgingAmong(
                $location->file(),
                $map->testsCovering($location->file(), $location->start(), $location->last()),
            );
            $controls += Unevidenced::needsVouching($mutant, $evidence->of($mutant->id())) && count($tests) > 0
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
     * These evidences, each mutant whose control passed in these runs
     * vouched for.
     */
    public function vouching(Mutants $mutants, Evidences $evidence, ControlRuns $runs, Controls $left): Evidences
    {
        $vouched = $evidence;

        foreach ($mutants as $mutant) {
            $control = $this->controls->controlOf($mutant);
            $vouched = $control instanceof Control
                && ! $left->has($control)
                && $runs->of($control)->end() === ControlEnd::Passed
                ? $vouched->with($mutant->id(), $evidence->of($mutant->id())->vouched())
                : $vouched;
        }

        return $vouched;
    }

    /**
     * The mutants, each unnamed kill whose control did not pass unjudged,
     * saying why, or unjudged, the time budget having run out before its
     * control, where it is among those left.
     */
    public function applied(Mutants $mutants, ControlRuns $runs, Controls $left): Mutants
    {
        return $this->controls->applied($mutants, $runs, $left, $this);
    }

    /** An unnamed kill as what its control found judges it. */
    public function judged(Mutant $mutant, ControlRun $run, Control $control): Mutant
    {
        $why = $run->why();

        return match ($run->end()) {
            ControlEnd::Passed => $mutant,
            ControlEnd::Failed => $mutant->unjudged(Reason::that(self::FAILS_UNMUTATED)),
            ControlEnd::OutOfMemory => $mutant->unjudged(Reason::that(self::OUT_OF_MEMORY)),
            ControlEnd::RanOut => $mutant->unjudged(Reason::that(self::RAN_OUT)),
            ControlEnd::Unrun => $mutant->unjudged(
                Reason::that(sprintf(self::UNRUN, $why instanceof NotGiven ? ControlRuns::NOT_RUN : $why)),
            ),
        };
    }
}
