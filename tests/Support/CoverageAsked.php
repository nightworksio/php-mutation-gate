<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_values;

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
use NightWorksIO\MutationGate\Core\NotGiven;
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
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;

/**
 * A runner that answers every coverage request so, or each run of some test
 * files as it is told to, keeping each request it was asked, and answers the
 * rest as another runner does.
 */
final class CoverageAsked implements Runner
{
    /** @var list<CoverageRun|CoverageRead|CoverageRan> */
    private array $asked = [];

    private CoverageMap|CannotJudge $files;

    private CannotJudge|NotGiven $placing;

    public function __construct(private readonly Runner $runner, private readonly CoverageMap|CannotJudge $answer)
    {
        $this->files = $answer;
        $this->placing = NotGiven::value();
    }

    /** This runner, which cannot tell which tests a file holds, for this reason. */
    public function unplacing(string $why): self
    {
        $runner = clone $this;
        $runner->placing = CannotJudge::because($why);

        return $runner;
    }

    /** This runner, answering each run of some test files so. */
    public function runningFiles(CoverageMap|CannotJudge $answer): self
    {
        $runner = clone $this;
        $runner->files = $answer;

        return $runner;
    }

    /** @return list<CoverageRun|CoverageRead|CoverageRan> each coverage request, in the order it was asked */
    public function asked(): array
    {
        return $this->asked;
    }

    /** @return list<CoverageRun> each run of the suite it was asked for, in the order it was asked */
    public function ran(): array
    {
        return array_values(array_filter(
            $this->asked,
            static fn(CoverageRun|CoverageRead|CoverageRan $asked): bool => $asked instanceof CoverageRun,
        ));
    }

    public function behaviour(): RunnerBehaviour
    {
        return $this->runner->behaviour();
    }

    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        return $this->runner->identity($withheld);
    }

    public function groups(Withheld $withheld): Groups|CannotJudge
    {
        return $this->runner->groups($withheld);
    }

    public function coverage(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap|CannotJudge
    {
        $this->asked[] = $request;

        return $request instanceof CoverageRun && $request->tests() instanceof TestPaths ? $this->files : $this->answer;
    }

    public function testsIn(Paths $files, CoverageMap $map): TestIds|CannotJudge
    {
        return $this->placing instanceof CannotJudge ? $this->placing : $this->runner->testsIn($files, $map);
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
        return $this->runner->mutate($request);
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
