<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * What a runner is asked to run for a coverage map: the whole suite, one
 * group, the tests a filter names, or the tests of some files, which a kept
 * map is measured again for (ADR-0023, decision 3), under coverage, across
 * some processes, withholding what the tests may not see, leaving the map in
 * a directory.
 */
final readonly class CoverageRun
{
    /** The directory, among a runner adapter's own files, that its own coverage run for a mutation run writes to. */
    public const string OWN_DIRECTORY = 'coverage';

    private function __construct(
        private WholeSuite|Group|Filter|TestPaths $tests,
        private Path $directory,
        private ProcessCount $processes,
        private Withheld $withheld,
        private SuiteName|NotGiven $suite,
    ) {
    }

    /** These tests run under coverage in one process, leaving the map in a directory. */
    public static function of(WholeSuite|Group|Filter|TestPaths $tests, Path $into): self
    {
        return new self($tests, $into, ProcessCount::single(), Withheld::standard(), NotGiven::value());
    }

    public function across(ProcessCount $processes): self
    {
        return clone($this, ['processes' => $processes]);
    }

    /** This run, withholding these variables from the tests as well as those it already withholds. */
    public function withholding(Withheld $withheld): self
    {
        return clone($this, ['withheld' => $this->withheld->and($withheld)]);
    }

    /** This run, of one suite's tests alone, as `--suite` asks (ADR-0025, decision 9). */
    public function inSuite(SuiteName $suite): self
    {
        return clone($this, ['suite' => $suite]);
    }

    /** The suite whose tests alone the run runs; none where it runs every suite's. */
    public function suite(): SuiteName|NotGiven
    {
        return $this->suite;
    }

    /** The variables the tests never see. */
    public function withheld(): Withheld
    {
        return $this->withheld;
    }

    public function tests(): WholeSuite|Group|Filter|TestPaths
    {
        return $this->tests;
    }

    /** Where the map is left. */
    public function directory(): Path
    {
        return $this->directory;
    }

    public function processes(): ProcessCount
    {
        return $this->processes;
    }
}
