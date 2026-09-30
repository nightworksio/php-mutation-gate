<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** Which tests ran the lines of a file, as a mutation run's opening map says. */
interface Covering
{
    /** The tests that ran any line from the first to the last of a file on disk. */
    public function testsCovering(DiskPath $file, Line $first, Line $last): TestIds;

    /** The map, with each file as the project spells it. */
    public function map(Project $project): CoverageMap;
}
