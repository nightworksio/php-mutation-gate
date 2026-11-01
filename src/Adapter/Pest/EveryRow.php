<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * A mutant's own run as an unpatched Pest selects it: the tests of a map
 * pest-plugin-mutate's filter of the tests that judge it selects, every row
 * of their data sets among them (see Selection).
 *
 * @internal
 */
final readonly class EveryRow implements RunSelection
{
    public function of(TestIds $judging, CoverageMap $map, Paths|WholeSuite|NotGiven $unfiltered): RunTests
    {
        return RunTests::of(Selection::of($judging)->among($map->tests()));
    }
}
