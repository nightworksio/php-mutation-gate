<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MemoryScan;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

/** The PHPUnit adapter's memory cap for a test that runs its mutants uncapped. */
final class PhpUnitScan
{
    public static function uncapped(Project $project): MemoryScan
    {
        $scan = MemoryScan::in($project, MemoryCap::none(), new CapDirectory());

        return $scan instanceof MemoryScan ? $scan : throw new LogicException('An uncapped run writes no cap.');
    }
}
