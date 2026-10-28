<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/** A mutant's own run of every row of the data sets the tests that judge it are rows of, among a map's tests. */
final readonly class EveryRowOfTheMethod implements RunSelection
{
    public function of(TestIds $judging, CoverageMap $map, Paths|WholeSuite|NotGiven $unfiltered): RunTests
    {
        $methods = array_map(self::methodOf(...), [...$judging]);

        return RunTests::of(TestIds::of(...array_values(array_filter(
            [...$map->tests()],
            static fn(TestId $test): bool => in_array(self::methodOf($test), $methods, strict: true),
        ))));
    }

    private static function methodOf(TestId $test): string
    {
        $method = TestMethod::of($test);

        return $method instanceof TestMethod ? TestMethod::id($method->className(), $method->method())->value() : $test->value();
    }
}
