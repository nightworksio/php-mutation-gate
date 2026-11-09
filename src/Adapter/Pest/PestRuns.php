<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\PackageFiles;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\PendingListings;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/**
 * The runs Pest starts for a project through its shell: each listing of a
 * set of suites, once, alone or started side by side with others, and its
 * mutation runs, through that shell or one that keeps what Pest printed.
 *
 * @internal
 */
final readonly class PestRuns
{
    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private PackageFiles $packages,
        private Patching $patching,
        private Remembered $remembered,
        private LimitBounds $bounds,
        private Clock $clock,
        private Bridges $bridges,
    ) {
    }

    /** What Pest lists for these suites, once, never seeing the variables withheld; or why it lists nothing. */
    public function listing(Withheld $withheld, Suites $suites): TestListing|CannotJudge
    {
        $project = $this->project;
        $shell = $this->shell;

        return $this->remembered->listing(
            $withheld,
            $suites,
            static fn(): TestListing|CannotJudge => Listed::of($project, $shell, $withheld, $suites),
        );
    }

    /**
     * What Pest lists for each set of suites, in their order, each listed
     * once: those not yet listed are started side by side, and each is taken
     * once asked for.
     */
    public function listingsStarted(Withheld $withheld, Suites ...$suites): PendingListings
    {
        $project = $this->project;
        $shell = $this->shell;

        return $this->remembered->listings(
            $withheld,
            static fn(Suites ...$missing): PendingListings => Listed::started($project, $shell, $withheld, ...$missing),
            ...$suites,
        );
    }

    /** A mutation run of the project, through this shell. */
    public function through(Shell $shell): MutationRun
    {
        return new MutationRun(
            $this->project,
            $shell,
            $this->files,
            $this->packages,
            $this->patching,
            $this->remembered,
            $this->listing(...),
            $this->bounds,
            clock: $this->clock,
            bridges: $this->bridges,
        );
    }
}
