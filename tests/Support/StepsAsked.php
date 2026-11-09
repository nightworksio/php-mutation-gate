<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\ListsSideBySide;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PendingListings;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;

/**
 * A runner that answers as another does and starts its listings side by
 * side, each listed as that runner lists it once they are taken, keeping each
 * step in the order it came: the listings started, a run's report read, the
 * listings taken.
 */
final class StepsAsked implements ListsSideBySide, Runner
{
    public const string STARTED = 'listings started';

    public const string READ = 'report read';

    public const string TAKEN = 'listings taken';

    /** @var list<string> */
    private array $steps = [];

    public function __construct(private readonly Runner $runner)
    {
    }

    /** @return list<string> each step, in the order it came */
    public function steps(): array
    {
        return $this->steps;
    }

    public function listingsStarted(Withheld $withheld, Suites ...$suites): PendingListings
    {
        $this->steps[] = self::STARTED;

        return PendingListings::taking(
            function () use ($withheld, $suites): array {
                $this->steps[] = self::TAKEN;
                $listed = [];

                foreach ($suites as $among) {
                    $listed[] = $this->runner->listing($withheld, $among);
                }

                return $listed;
            },
            static function (): void {
            },
        );
    }

    public function behaviour(): RunnerBehaviour
    {
        return $this->runner->behaviour();
    }

    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        return $this->runner->identity($withheld);
    }

    public function listing(Withheld $withheld, Suites $suites): TestListing|CannotJudge
    {
        return $this->runner->listing($withheld, $suites);
    }

    public function coverage(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap|CannotJudge
    {
        if ($request instanceof CoverageRan) {
            $this->steps[] = self::READ;
        }

        return $this->runner->coverage($request);
    }

    public function testsIn(Paths $files, CoverageMap $map): TestIds|CannotJudge
    {
        return $this->runner->testsIn($files, $map);
    }

    public function judges(Path $file, CoverageMap $map): Paths|CannotJudge
    {
        return $this->runner->judges($file, $map);
    }

    public function startUp(Path $file, Withheld $withheld): Seconds|CannotJudge
    {
        return $this->runner->startUp($file, $withheld);
    }

    public function mutate(
        MutationRequest $request,
        PreChecker $preChecker = new NoPreCheck(),
    ): MutationResult|CannotJudge {
        return $this->runner->mutate($request, $preChecker);
    }

    public function controls(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        return $this->runner->controls($request, $controls);
    }

    public function retry(MutationRequest $request, Mutants $mutants, Seconds $limit): Mutants|CannotJudge
    {
        return $this->runner->retry($request, $mutants, $limit);
    }

    public function reproduce(
        Reproducible $mutant,
        MutationRequest $request,
        Seconds $limit,
    ): Reproduction|CannotJudge {
        return $this->runner->reproduce($mutant, $request, $limit);
    }

    public function checkable(Mutant $mutant): Checkable|CannotJudge
    {
        return $this->runner->checkable($mutant);
    }

    public function markers(Paths $files): Markers|CannotJudge
    {
        return $this->runner->markers($files);
    }

    public function definitions(): Paths
    {
        return $this->runner->definitions();
    }

    public function names(TestIds $tests, Withheld $withheld): TestNames|CannotJudge
    {
        return $this->runner->names($tests, $withheld);
    }

    public function rootedAt(Path $package, Paths $tests): Runner|CannotJudge
    {
        return $this->runner->rootedAt($package, $tests);
    }
}
