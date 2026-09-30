<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

/** The extensions php-code-coverage collects line coverage with, pcov first where both are loaded. */
final readonly class Driver
{
    public const string PCOV = 'pcov';

    public const string XDEBUG = 'xdebug';
}
