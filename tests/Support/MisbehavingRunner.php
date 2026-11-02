<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;
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
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
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
 * A runner that answers as another does, but for the questions a test gives
 * an answer of its own, so a contract can be shown each way a runner breaks
 * it. A runner it is rooted as answers as this one does.
 */
final readonly class MisbehavingRunner implements Runner
{
    /**
     * @param (Closure(Withheld): (Identity|CannotJudge))|false                                  $identity
     * @param (Closure(): RunnerBehaviour)|false                                                 $behaviour
     * @param (Closure(Withheld, Suites): (TestListing|CannotJudge))|false                       $listing
     * @param (Closure(CoverageRun|CoverageRead|CoverageRan): (CoverageMap|CannotJudge))|false   $coverage
     * @param (Closure(Paths, CoverageMap): (TestIds|CannotJudge))|false                         $testsIn
     * @param (Closure(Path, CoverageMap): (Paths|CannotJudge))|false                            $judges
     * @param (Closure(Path, Withheld): (Seconds|CannotJudge))|false                             $startUp
     * @param (Closure(MutationRequest): (MutationResult|CannotJudge))|false                     $mutate
     * @param (Closure(Mutant): (Checkable|CannotJudge))|false                                   $checkable
     * @param (Closure(MutationRequest, Mutants, Seconds): (Mutants|CannotJudge))|false          $retry
     * @param (Closure(Reproducible, MutationRequest, Seconds): (Reproduction|CannotJudge))|false $reproduce
     * @param (Closure(Paths): (Markers|CannotJudge))|false                                      $markers
     * @param (Closure(): Paths)|false                                                           $definitions
     * @param (Closure(TestIds, Withheld): (TestNames|CannotJudge))|false                        $names
     * @param (Closure(Path, Paths): (Runner|CannotJudge))|false                                 $rootedAt
     */
    public function __construct(
        private Runner $runner,
        private Closure|false $identity = false,
        private Closure|false $behaviour = false,
        private Closure|false $listing = false,
        private Closure|false $coverage = false,
        private Closure|false $testsIn = false,
        private Closure|false $judges = false,
        private Closure|false $startUp = false,
        private Closure|false $mutate = false,
        private Closure|false $checkable = false,
        private Closure|false $retry = false,
        private Closure|false $reproduce = false,
        private Closure|false $markers = false,
        private Closure|false $definitions = false,
        private Closure|false $names = false,
        private Closure|false $rootedAt = false,
    ) {
    }

    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        return $this->identity instanceof Closure ? ($this->identity)($withheld) : $this->runner->identity($withheld);
    }

    public function behaviour(): RunnerBehaviour
    {
        return $this->behaviour instanceof Closure ? ($this->behaviour)() : $this->runner->behaviour();
    }

    public function listing(Withheld $withheld, Suites $suites): TestListing|CannotJudge
    {
        return $this->listing instanceof Closure
            ? ($this->listing)($withheld, $suites)
            : $this->runner->listing($withheld, $suites);
    }

    public function coverage(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap|CannotJudge
    {
        return $this->coverage instanceof Closure ? ($this->coverage)($request) : $this->runner->coverage($request);
    }

    public function testsIn(Paths $files, CoverageMap $map): TestIds|CannotJudge
    {
        return $this->testsIn instanceof Closure ? ($this->testsIn)($files, $map) : $this->runner->testsIn($files, $map);
    }

    public function judges(Path $file, CoverageMap $map): Paths|CannotJudge
    {
        return $this->judges instanceof Closure ? ($this->judges)($file, $map) : $this->runner->judges($file, $map);
    }

    public function startUp(Path $file, Withheld $withheld): Seconds|CannotJudge
    {
        return $this->startUp instanceof Closure
            ? ($this->startUp)($file, $withheld)
            : $this->runner->startUp($file, $withheld);
    }

    public function mutate(MutationRequest $request, PreChecker $preChecker = new NoPreCheck()): MutationResult|CannotJudge
    {
        return $this->mutate instanceof Closure ? ($this->mutate)($request) : $this->runner->mutate($request, $preChecker);
    }

    public function controls(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        return $this->runner->controls($request, $controls);
    }

    public function checkable(Mutant $mutant): Checkable|CannotJudge
    {
        return $this->checkable instanceof Closure ? ($this->checkable)($mutant) : $this->runner->checkable($mutant);
    }

    public function retry(MutationRequest $request, Mutants $mutants, Seconds $most): Mutants|CannotJudge
    {
        return $this->retry instanceof Closure
            ? ($this->retry)($request, $mutants, $most)
            : $this->runner->retry($request, $mutants, $most);
    }

    public function reproduce(Reproducible $mutant, MutationRequest $request, Seconds $most): Reproduction|CannotJudge
    {
        return $this->reproduce instanceof Closure
            ? ($this->reproduce)($mutant, $request, $most)
            : $this->runner->reproduce($mutant, $request, $most);
    }

    public function markers(Paths $files): Markers|CannotJudge
    {
        return $this->markers instanceof Closure ? ($this->markers)($files) : $this->runner->markers($files);
    }

    public function definitions(): Paths
    {
        return $this->definitions instanceof Closure ? ($this->definitions)() : $this->runner->definitions();
    }

    public function names(TestIds $tests, Withheld $withheld): TestNames|CannotJudge
    {
        return $this->names instanceof Closure ? ($this->names)($tests, $withheld) : $this->runner->names($tests, $withheld);
    }

    public function rootedAt(Path $package, Paths $tests): Runner|CannotJudge
    {
        return $this->rootedAt instanceof Closure
            ? ($this->rootedAt)($package, $tests)
            : $this->runner->rootedAt($package, $tests);
    }
}
