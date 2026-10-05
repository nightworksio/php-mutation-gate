<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** A coverage map holding these tests, in their order, each running line 1 of `src/Covered.php`. */
final readonly class MapOfTests
{
    public static function of(TestIds $tests): CoverageMap
    {
        $map = CoverageMap::empty();

        foreach ($tests as $test) {
            $map = $map->covered(Path::of('src/Covered.php'), Line::of(1), $test);
        }

        return $map;
    }
}
