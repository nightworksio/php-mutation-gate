<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

/** Whether the project's Infection carries `infection:patch`, which decides whose limit each mutant gets. */
enum PatchState
{
    /** Each mutant gets the gate's limit, within `timeouts.seconds` and `timeouts.most`, and none is skipped. */
    case Applied;

    /** Each mutant gets Infection's own limit under `timeouts.most`, with no floor, and the report says so. */
    case Missing;
}
