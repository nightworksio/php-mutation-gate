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
 * A mutant's own run as a patched Pest selects it: the tests of a map its
 * exact filter of the tests that judge it selects, a row of a data set alone
 * (see Selection), by that filter. Where the run left its filter out, as too
 * long to pass, every test of the test files it loaded, or of every test
 * file, by those files.
 *
 * @internal
 */
final readonly class ExactRows implements RunSelection
{
    private MapSuites $suites;

    public function __construct(private TestFiles $files)
    {
        $this->suites = new MapSuites();
    }

    public function of(TestIds $judging, CoverageMap $map, Paths|WholeSuite|NotGiven $unfiltered): RunTests
    {
        if ($unfiltered instanceof Paths) {
            return RunTests::loading($this->files->holding($unfiltered, $map), $unfiltered);
        }

        $tests = $map->tests();
        $selection = $unfiltered instanceof WholeSuite
            ? NotGiven::value()
            : Selection::exactly($judging, $this->suites->of($map));

        return $selection instanceof Selection && $selection->fits()
            ? RunTests::filtered($selection->among($tests), $selection->filter())
            : RunTests::loading($tests, WholeSuite::tests());
    }
}
