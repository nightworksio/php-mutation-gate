<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/**
 * The mutants a run killed with no test named as the killer, or only by
 * tests that errored rather than failed: those a run that could not load
 * everything its tests need leaves, as a name it could not resolve errors.
 * Mutants that share Pest's id share its doubt.
 */
final readonly class DoubtfulKills
{
    public static function in(MutationResult $result, string $results): Mutants
    {
        $records = Records::in($results);
        $errored = $records instanceof CannotJudge ? [] : self::erroredOnly($records);

        return Mutants::of(...array_filter(
            [...$result->mutants()],
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Killed
                && (count($mutant->killers()) === 0 || array_key_exists($mutant->nativeId(), $errored)),
        ));
    }

    /** @return array<string, int> the native ids of the mutants killed only by tests that errored */
    private static function erroredOnly(Records $records): array
    {
        $errored = [];

        foreach ($records->planned() as $planned) {
            if ($records->killedByErrorsOnly($planned)) {
                $errored[$planned->id()] = 0;
            }
        }

        return $errored;
    }
}
