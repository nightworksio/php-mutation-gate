<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Test\Role;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * Every file of test cases in the suite, each with the coverage map's tests
 * the runner places in it by its own rules; a file it cannot place tests in
 * holds none.
 */
final readonly class TestsOfTheSuite
{
    public static function placed(Adapters $adapters, Suite $suite, CoverageMap $map): TestPlaces
    {
        $places = TestPlaces::none();

        foreach ($suite->files() as $file) {
            if ($file->role() !== Role::TestCase) {
                continue;
            }

            $path = $file->fingerprint()->path();
            $tests = $adapters->runner->testsIn(Paths::of($path), $map);
            $places = $places->placing($path, $tests instanceof TestIds ? $tests : TestIds::none());
        }

        return $places;
    }
}
