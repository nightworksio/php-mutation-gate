<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Format\Secrets;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The evidence of a shard's kills as its result keeps it (ADR-0014,
 * decision 16): of each of its mutants killed as it ends, what a process
 * printed screened for the secrets the gate withholds before its tail is
 * cut, and kept only where none is in it, so none reaches the result.
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

    /**
     * The evidence, what its process printed kept only where it holds no
     * secret, and nothing of it where it does (see Secrets).
     */
    private static function of(Evidence $evidence, Secrets $secrets): Evidence
    {
        $ended = $evidence->ended();

        if (! $ended instanceof Ended) {
            return $evidence;
        }

        $printed = $ended->printed();
        $screened = $printed instanceof NotGiven ? $printed : $secrets->screened($printed, cut: $ended->wasCut());

        return $evidence->withEnded(Ended::screened($ended, $screened));
    }
}
