<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

/** What `mutation-gate hook` does with the gate's hooks. */
enum HookAction: string
{
    case Install = 'install';
    case Uninstall = 'uninstall';
}
