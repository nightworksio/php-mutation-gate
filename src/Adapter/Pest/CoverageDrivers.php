<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Adapter\Pest\Split\Recorded;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Doctor\Check\Xdebug;

/**
 * The coverage drivers a mutation run and every process it starts run with
 * (ADR-0147): pcov on where its streams record lines with pcov, whatever
 * the process that starts it turned off, else off under `pest.pcovOff`,
 * and Xdebug's mode off under `pest.xdebugOff`. A mutation run reads no
 * coverage but what its streams record.
 *
 * @internal
 */
final readonly class CoverageDrivers
{
    /** The value of pcov's variable that turns it off. */
    private const string PCOV_OFF = '0';

    /** The value of pcov's variable that turns it on. */
    private const string PCOV_ON = '1';

    private const string XDEBUG_OFF = Xdebug::OFF;

    /**
     * What a mutation run's environment sets, where its streams record lines with pcov or not.
     *
     * @return array<string, string>
     */
    public static function of(Patching $patching, bool $recordsWithPcov): array
    {
        return [
            ...match (true) {
                $recordsWithPcov => [Recorded::ENABLED => self::PCOV_ON],
                $patching->steps()->on(Toggle::PestPcovOff) => [Recorded::ENABLED => self::PCOV_OFF],
                default => [],
            },
            ...$patching->steps()->on(Toggle::PestXdebugOff) ? [Xdebug::MODE_VARIABLE => self::XDEBUG_OFF] : [],
        ];
    }
}
