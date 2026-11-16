<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Process\Cgroups;
use NightWorksIO\MutationGate\Adapter\Process\SessionLeader;
use NightWorksIO\MutationGate\Adapter\Runtime\SystemGauge;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Processes;

/**
 * How every process the gate starts is held, told to the gate's own process
 * once its settings are read (ADR-0158): in a session of its own as
 * `processes.group` says, and in a cgroup of its own as `processes.contain`
 * says, which in CI it may hand itself; or why the settings require a hold this system cannot give.
 *
 * @internal
 */
final readonly class HeldProcesses
{
    public static function by(Processes $processes, Variables $environment): true|CannotJudge
    {
        $led = SessionLeader::toldHere($processes->group());
        $contained = Cgroups::toldHere($processes, $environment->inCi(), SystemGauge::MEMINFO);

        return $led instanceof CannotJudge ? $led : $contained;
    }
}
