<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

/** Whether the project's installed Infection carries `infection:patch` (ADR-0008, decision 2). */
enum InfectionPatch
{
    /** Infection gives each mutant the gate's limit. */
    case Applied;

    /** Infection gives each mutant its own limit, with no floor. */
    case Missing;

    /** No Infection is installed. */
    case NotInstalled;
}
