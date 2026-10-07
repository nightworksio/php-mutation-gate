<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function count;
use function is_string;

use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\FatalError;

/**
 * How a kill that names no killer ended, as far as Infection's log tells
 * (ADR-0014, decision 16): what its process printed, where the log holds
 * it, and whether PHP's record of a fatal error is in that (see
 * FatalError). Infection logs neither the code the process exited with nor
 * whether a signal ended it, nor the order its tests ran in, so those are
 * not given.
 */
final readonly class KillOutput
{
    /** The evidence of a mutant whose process printed this, where its log holds what it printed. */
    public static function evidenceOf(Mutant $mutant, string|NotGiven $printed): Evidence
    {
        $unnamed = $mutant->status() === MutantStatus::Killed && count($mutant->killers()) === 0;

        return $unnamed && is_string($printed)
            ? Evidence::none()->withEnded(
                Ended::of(NotGiven::value(), NotGiven::value(), $printed)->withFatal(FatalError::in($printed)),
            )
            : Evidence::none();
    }
}
