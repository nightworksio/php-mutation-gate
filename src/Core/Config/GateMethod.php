<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** A method of the PHP builder's `Gate` that a written config calls, as the builder names it (ADR-0002). */
enum GateMethod: string
{
    case Runner = 'runner';
    case Withholding = 'withholding';
    case CappedAt = 'cappedAt';
    case InWorkers = 'inWorkers';
    case Trees = 'trees';
    case NewCode = 'newCode';
    case Security = 'security';
    case Reporting = 'reporting';
    case Extensions = 'extensions';
    case Preset = 'preset';
    case TreeSource = 'treeSource';
    case Ignoring = 'ignoring';
    case With = 'with';
}
