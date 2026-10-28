<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * Which tests a mutant's own run selects, and how, by the runner's own rules
 * (ADR-0008): a control that reproduces the run runs those, selected the
 * same way, and is allowed the limit their time sets.
 */
interface RunSelection
{
    /**
     * The tests of a map a mutant's own run runs, given the tests that judge
     * the mutant and the test files its run loaded with no filter, or every
     * one, where the runner left its filter out.
     */
    public function of(TestIds $judging, CoverageMap $map, Paths|WholeSuite|NotGiven $unfiltered): RunTests;
}
