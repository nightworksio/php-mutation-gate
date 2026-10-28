<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * Why a kill its control does not vouch for is unjudged (see KillControls
 * and UnnamedKillControls): where its tests fail unmutated too, run out of
 * the memory cap or their limit, or never run, each said in a kill's own
 * words.
 */
final readonly class KillReasons
{
    private function __construct(
        private string $failed,
        private string $outOfMemory,
        private string $ranOut,
        private string $unrun,
    ) {
    }

    /** These reasons; the last says why the control never ran where its `%s` stands. */
    public static function of(string $failed, string $outOfMemory, string $ranOut, string $unrun): self
    {
        return new self($failed, $outOfMemory, $ranOut, $unrun);
    }

    /**
     * A kill as what its control, of these tests, found judges it: standing
     * where the control passed, its tests failing without it where the
     * control failed, and unjudged otherwise.
     */
    public function judged(Mutant $mutant, ControlRun $run, TestIds $ran): Mutant
    {
        $why = $run->why();

        return match ($run->end()) {
            ControlEnd::Passed => $mutant,
            ControlEnd::Failed => $mutant->unjudged(
                $run->unmutatedFailure($ran, Reason::that($this->failed)),
            ),
            ControlEnd::OutOfMemory => $mutant->unjudged(Reason::that($this->outOfMemory)),
            ControlEnd::RanOut => $mutant->unjudged(Reason::that($this->ranOut)),
            ControlEnd::Unrun => $mutant->unjudged(
                Reason::that(sprintf($this->unrun, $why instanceof NotGiven ? ControlRuns::NOT_RUN : $why)),
            ),
        };
    }
}
