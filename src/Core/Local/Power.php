<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

/**
 * Where the machine draws its power from, as `local.onBattery` reads it
 * (ADR-0120).
 */
enum Power: string
{
    /** On power, or a source the gate cannot read. */
    case Mains = 'mains';

    /** On battery, or in Low Power Mode. */
    case Saving = 'saving';
}
