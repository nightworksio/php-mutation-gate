<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\Doctor\Check\Config;
use NightWorksIO\MutationGate\Core\Doctor\Check\CoverageDriver;
use NightWorksIO\MutationGate\Core\Doctor\Check\InfectionImport;
use NightWorksIO\MutationGate\Core\Doctor\Check\Opcache;
use NightWorksIO\MutationGate\Core\Doctor\Check\Php;
use NightWorksIO\MutationGate\Core\Doctor\Check\Runners;
use NightWorksIO\MutationGate\Core\Doctor\Check\TreesFound;
use NightWorksIO\MutationGate\Core\Doctor\Check\Workspace;
use NightWorksIO\MutationGate\Core\Doctor\Check\Xdebug;

/**
 * Every check `doctor` runs, and `plan` and a one-process `run` begin with,
 * over what an adapter observed (ADR-0017, decisions 9 and 10).
 */
final readonly class Diagnosis
{
    public static function of(Observations $observed): Findings
    {
        return Findings::none()->and(
            Php::in($observed),
            CoverageDriver::in($observed),
            Xdebug::in($observed),
            Opcache::in($observed),
            Runners::in($observed),
            Config::in($observed),
            TreesFound::in($observed),
            Workspace::in($observed),
            InfectionImport::in($observed),
        );
    }
}
