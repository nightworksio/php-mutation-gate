<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * A reporter whose sending needs a credential, which under `--deliver-later` leaves its payload for `deliver`
 * rather than send it (ADR-0007 decision 5).
 */
interface Deferring
{
    /** A delivery, with what this reporter would send of the verdict now; unchanged where it would send nothing. */
    public function deferred(Verdict $verdict, Delivery $delivery): Delivery;
}
