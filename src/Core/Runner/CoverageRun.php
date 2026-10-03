<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * What a runner is asked to run for a coverage map: the whole suite, one
 * group or the tests a filter names, under coverage, across some processes,
 * withholding what the tests may not see, leaving the map in a directory.
 */
final readonly class CoverageRun
{
    /** The directory, among a runner adapter's own files, that its own coverage run for a mutation run writes to. */
    public const string OWN_DIRECTORY = 'coverage';

    private function __construct(
        private WholeSuite|Group|Filter $tests,
        private Path $directory,
        private Processes $processes,
        private Withheld $withheld,
    ) {
    }

    /** These tests run under coverage in one process, leaving the map in a directory. */
    public static function of(WholeSuite|Group|Filter $tests, Path $into): self
    {
        return new self($tests, $into, Processes::single(), Withheld::standard());
    }

    public function across(Processes $processes): self
    {
        return clone($this, ['processes' => $processes]);
    }

    /** This run, withholding these variables from the tests as well as those it already withholds. */
    public function withholding(Withheld $withheld): self
    {
        return clone($this, ['withheld' => $this->withheld->and($withheld)]);
    }

    /** The variables the tests never see. */
    public function withheld(): Withheld
    {
        return $this->withheld;
    }

    public function tests(): WholeSuite|Group|Filter
    {
        return $this->tests;
    }

    /** Where the map is left. */
    public function directory(): Path
    {
        return $this->directory;
    }

    public function processes(): Processes
    {
        return $this->processes;
    }
}
