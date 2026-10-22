<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Sampling;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a sample is drawn from, which the author does not choose (ADR-0020):
 * `sampling.seed` where it is set; or else, in a change-scoped run, the
 * commit its change's base ref is at, which is the default branch's, and the
 * ref itself where git cannot say; and otherwise the commit the plan is made
 * on.
 */
final readonly class SampleSeed
{
    public static function of(
        Adapters $adapters,
        Sampling $sampling,
        Revision $head,
        ChangeBase|CannotTell $base,
    ): string {
        $given = $sampling->seed();
        $judged = $base instanceof ChangeBase ? $adapters->changes->judged($base->ref()) : NotGiven::value();

        return match (true) {
            ! $given instanceof NotGiven => $given,
            $judged instanceof JudgedCommit => $judged->commit()->name(),
            $base instanceof ChangeBase => $base->ref()->name(),
            default => $head->name(),
        };
    }
}
