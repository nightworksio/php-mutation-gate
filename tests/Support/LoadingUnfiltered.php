<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/** A mutant's own run of exactly the tests that judge it, by the test files it loaded with no filter where it did. */
final readonly class LoadingUnfiltered implements RunSelection
{
    public function of(TestIds $judging, CoverageMap $map, Paths|WholeSuite|NotGiven $unfiltered): RunTests
    {
        return $unfiltered instanceof NotGiven ? RunTests::of($judging) : RunTests::loading($judging, $unfiltered);
    }
}
