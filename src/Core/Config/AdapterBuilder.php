<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** A class of the PHP builder that chooses an adapter, as a written config names it (ADR-0002). */
enum AdapterBuilder: string
{
    case Ci = 'Ci';
    case Proofs = 'Proofs';
    case Report = 'Report';
    case Runner = 'Runner';
    case Source = 'Source';
    case StaticCheck = 'StaticCheck';
}
