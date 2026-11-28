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

/** A runner's selection of exactly the judging tests, whose controls load the files of their tests alone. */
final readonly class LoadingTheFilesOfItsTests implements RunSelection
{
    public function of(TestIds $judging, CoverageMap $map, Paths|WholeSuite|NotGiven $unfiltered): RunTests
    {
        return RunTests::of($judging);
    }

    public function replaysKills(): bool
    {
        return false;
    }

    public function loadsTheFilesOfItsTests(): bool
    {
        return true;
    }
}
