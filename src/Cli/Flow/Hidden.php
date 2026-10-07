<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Format\Secrets;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

/**
 * The evidence of a shard's kills as its result keeps it (ADR-0014,
 * decision 16): of each of its mutants killed as it ends, each secret the
 * gate withholds hidden in what a process printed before its tail is cut,
 * so none reaches the result or a log that prints it.
 */
final readonly class Hidden
{
    public static function in(Evidences $evidence, Mutants $mutants, Secrets $secrets): Evidences
    {
        $hidden = Evidences::none();

        foreach ($mutants as $mutant) {
            if ($mutant->status() === MutantStatus::Killed) {
                $hidden = $hidden->with($mutant->id(), self::of($evidence->of($mutant->id()), $secrets));
            }
        }

        return $hidden;
    }

    private static function of(Evidence $evidence, Secrets $secrets): Evidence
    {
        $ended = $evidence->ended();

        return $ended instanceof Ended
            ? $evidence->withEnded(Ended::of($ended->code(), $ended->signalled(), $secrets->hidden($ended->printed())))
            : $evidence;
    }
}
