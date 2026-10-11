<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Adapter\Mago\Mago;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Port\Processes;

use function sprintf;

/** Mago in a test's project, as the tests of the Mago adapter start it. */
final readonly class MagoProjects
{
    /** Mago in a project, reading the config the gate hands it, if any, starting its processes through these. */
    public static function in(string $project, string $options = '{}', string $vendor = '', Processes $processes = new LocalProcesses(new SystemClock())): Mago
    {
        $mago = Mago::fromOptions(
            Configs::options($options),
            $project,
            $vendor === '' ? sprintf('%s/vendor', $project) : $vendor,
            $processes,
        );

        return $mago instanceof Mago ? $mago : throw new LogicException('No Mago.');
    }
}
