<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;

use function sprintf;

/**
 * The unmutated control of each kill a test is named for (ADR-0014,
 * decision 18): the tests that killed it, run with its file served
 * unmutated as its mutant was, allowed the standard mutant limit of their
 * time as the coverage map timed them, once for each file and set of
 * killers. The kill stands only where they pass there; where they fail, run
 * out of the limit or never run, nothing tells the mutant from its original,
 * and it is unjudged, saying why. A kill no test is named for has no control:
 * its evidence judges it (see Unevidenced).
 */
final readonly class KillControls implements ControlJudge
{
    /** Why a kill is unjudged where its control fails. */
    public const string FAILS_UNMUTATED
        = 'Killed, but the tests that killed it fail as well with the file unmutated, served as its mutant was.';

    /** Why a kill is unjudged where its control runs out of the memory cap. */
    public const string OUT_OF_MEMORY
        = 'Killed, but the tests that killed it run out of the memory cap with the file unmutated too.';

    /** Why a kill is unjudged where its control runs out of its limit. */
    public const string RAN_OUT
        = 'Killed, but the tests that killed it run out of their limit with the file unmutated too.';

    /** Why a kill is unjudged where its control never ran. */
    public const string UNRUN
        = 'Killed, but no unmutated control of the tests that killed it ran, so nothing vouches for it: %s.';

    private function __construct(private MutantControls $controls)
    {
    }

    /** The control of each of these mutants killed by a test named, its limit within these bounds. */
    public static function of(Mutants $mutants, CoverageMap $map, LimitBounds $bounds): self
    {
        $controls = [];

        foreach ($mutants as $mutant) {
            $killers = $mutant->killers();
            $controls += $mutant->status() === MutantStatus::Killed && count($killers) > 0
                ? [$mutant->id()->key() => Control::of(
                    $mutant->location()->file(),
                    $killers,
                    MutantLimit::standard()->of(OwnTime::of($map, $killers), $bounds),
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
     * The mutants, each kill standing where its control passed and unjudged,
     * saying why, otherwise; or unjudged, the time budget having run out
     * before its control, where it is among those left.
     */
    public function applied(Mutants $mutants, ControlRuns $runs, Controls $left): Mutants
    {
        return $this->controls->applied($mutants, $runs, $left, $this);
    }

    /** A kill as what its control found judges it. */
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
