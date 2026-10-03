<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;

/**
 * Which file holds each test of a map, by the names a fake runner gives its
 * tests, and the map a run of some of those files measures: their tests'
 * entries alone.
 */
final readonly class FilesOfTests
{
    public function __construct(private TestNames $names)
    {
    }

    /** The map's tests whose names place them in one of these files. */
    public function holding(Paths $files, CoverageMap $map): TestIds
    {
        $held = TestIds::none();

        foreach ($map->tests() as $test) {
            $name = $this->names->testOf($test);
            $held = $name instanceof TestName && $files->has($name->file()) ? $held->with($test) : $held;
        }

        return $held;
    }

    /** What a run of these files measures of a map: the entries of the tests they hold, and no other. */
    public function measured(Paths $files, CoverageMap $map): CoverageMap
    {
        $held = $this->holding($files, $map);
        $others = TestIds::none();

        foreach ($map->tests() as $test) {
            $others = $held->has($test) ? $others : $others->with($test);
        }

        return Remeasured::over($map, $others, CoverageMap::empty());
    }
}
