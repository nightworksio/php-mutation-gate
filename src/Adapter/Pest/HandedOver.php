<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The map another job handed over, as a mutation run that opened on it covers
 * each line: Pest's opening map is that map again, so the gate reads the one
 * it already holds rather than a copy it would have to read back.
 *
 * @internal
 */
final readonly class HandedOver implements Covering
{
    public function __construct(private CoverageMap $map, private Project $project)
    {
    }

    public function testsCovering(DiskPath $file, Line $first, Line $last): TestIds
    {
        return $this->map->testsCovering($this->project->relative($file->value()), $first, $last);
    }

    public function map(Project $project): CoverageMap
    {
        return $this->map;
    }

    public function tests(): TestIds
    {
        return $this->map->tests();
    }
}
