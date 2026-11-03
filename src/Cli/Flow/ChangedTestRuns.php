<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\ChangedTests;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Reach\TestRuns;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * What each test the runner places in a changed test file covers, by the
 * coverage map (ADR-0065), and only in a changed file under a test
 * directory: a file it places no test in holds none. Where
 * `reach.changedTests` is `file`, it places none and narrows nothing.
 *
 * @internal
 */
final readonly class ChangedTestRuns
{
    public static function of(
        ChangedTests $mode,
        Changes|CannotTell $changes,
        Layout $layout,
        Packages $packages,
        Adapters $adapters,
        CoverageMap $map,
    ): TestRuns {
        if ($mode === ChangedTests::File) {
            return TestRuns::wholeFiles();
        }

        $places = TestPlaces::none();

        foreach ($changes instanceof Changes ? $changes : Changes::none() as $change) {
            $path = $change->path();
            $tests = $layout->isTest($path->relativeTo($packages->holding($path)->path()))
                ? $adapters->runner->testsIn(Paths::of($path), $map)
                : TestIds::none();
            $places = $tests instanceof TestIds ? $places->placing($path, $tests) : $places;
        }

        return TestRuns::of($map, $places);
    }
}
