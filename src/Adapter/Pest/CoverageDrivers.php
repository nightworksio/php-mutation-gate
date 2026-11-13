<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Adapter\Pest\Split\Recorded;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Doctor\Check\Xdebug;

/**
 * The coverage drivers a mutation run and every process it starts run with
 * (ADR-0147): pcov off under `pest.pcovOff`, unless its streams record
 * lines with pcov, and Xdebug's mode off under `pest.xdebugOff`. A mutation
 * run reads no coverage but what its streams record.
 *
 * @internal
 */
final readonly class CoverageDrivers
{
    /** The value of pcov's variable that turns it off. */
    private const string PCOV_OFF = '0';

    private const string XDEBUG_OFF = Xdebug::OFF;

    /**
     * What a mutation run's environment sets, where its streams record lines with pcov or not.
     *
     * @return array<string, string>
     */
    public static function of(Patching $patching, bool $recordsWithPcov): array
    {
        return [
            ...$patching->toggled(Toggle::PestPcovOff) && ! $recordsWithPcov
                ? [Recorded::ENABLED => self::PCOV_OFF]
                : [],
            ...$patching->toggled(Toggle::PestXdebugOff) ? [Xdebug::MODE_VARIABLE => self::XDEBUG_OFF] : [],
        ];
    }
}
