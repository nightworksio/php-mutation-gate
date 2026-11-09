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
use NightWorksIO\MutationGate\Core\Runner\ControlsSideBySide;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PendingControls;
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
 * A scripted runner that starts its controls beside the gate's other work:
 * each set of controls started is recorded, and what they find is the
 * scripted runner's answer, taken when the gate takes it.
 */
final class SideBySideRunner implements ControlsSideBySide, Runner
{
    /** @var list<string> every call to start or take controls, and every retry, in order */
    private array $calls = [];

    private function __construct(private readonly ScriptedRunner $scripted)
    {
    }

    public static function over(ScriptedRunner $scripted): self
    {
        return new self($scripted);
    }

    /** @return list<string> every call to start or take controls, and every retry, in order */
    public function calls(): array
    {
        return $this->calls;
    }

    public function controlsStarted(MutationRequest $request, Controls $controls): PendingControls
    {
        $this->calls[] = 'started';

        return PendingControls::taking(
            function () use ($request, $controls): ControlRuns|CannotJudge {
                $this->calls[] = 'taken';

                return $this->scripted->controls($request, $controls);
            },
            function (): void {
                $this->calls[] = 'stopped';
            },
        );
    }

    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        return $this->scripted->identity($withheld);
    }

    public function behaviour(): RunnerBehaviour
    {
        return $this->scripted->behaviour();
    }

    public function listing(Withheld $withheld, Suites $suites): TestListing|CannotJudge
    {
        return $this->scripted->listing($withheld, $suites);
    }

    public function coverage(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap|CannotJudge
    {
        return $this->scripted->coverage($request);
    }

    public function testsIn(Paths $files, CoverageMap $map): TestIds|CannotJudge
    {
        return $this->scripted->testsIn($files, $map);
    }

    public function judges(Path $file, CoverageMap $map): Paths|CannotJudge
    {
        return $this->scripted->judges($file, $map);
    }

    public function startUp(Path $file, Withheld $withheld): Seconds|CannotJudge
    {
        return $this->scripted->startUp($file, $withheld);
    }

    public function mutate(MutationRequest $request, PreChecker $preChecker = new NoPreCheck()): MutationResult|CannotJudge
    {
        return $this->scripted->mutate($request, $preChecker);
    }

    public function controls(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        $this->calls[] = 'controls';

        return $this->scripted->controls($request, $controls);
    }

    public function checkable(Mutant $mutant): Checkable|CannotJudge
    {
        return $this->scripted->checkable($mutant);
    }

    public function retry(MutationRequest $request, Mutants $mutants, Seconds $most): Mutants|CannotJudge
    {
        $this->calls[] = 'retry';

        return $this->scripted->retry($request, $mutants, $most);
    }

    public function reproduce(Reproducible $mutant, MutationRequest $request, Seconds $most): Reproduction|CannotJudge
    {
        return $this->scripted->reproduce($mutant, $request, $most);
    }

    public function markers(Paths $files): Markers|CannotJudge
    {
        return $this->scripted->markers($files);
    }

    public function definitions(): Paths
    {
        return $this->scripted->definitions();
    }

    public function names(TestIds $tests, Withheld $withheld): TestNames|CannotJudge
    {
        return $this->scripted->names($tests, $withheld);
    }

    public function rootedAt(Path $package, Paths $tests): Runner|CannotJudge
    {
        return $this->scripted->rootedAt($package, $tests);
    }
}
