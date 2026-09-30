<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use NightWorksIO\MutationGate\Core\Ci\CiRun;

use function sprintf;

/** The one line an alert leads with: the gate, the event, and where, as *mutation-gate: failed on octo/gate main*. */
final readonly class AlertTitle
{
    public static function of(Alert $alert, CiRun $run): string
    {
        return sprintf('mutation-gate: %s on %s %s', $alert->event()->said(), $run->repository(), $run->refName());
    }
}
