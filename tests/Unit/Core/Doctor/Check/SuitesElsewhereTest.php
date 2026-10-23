<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\SuitesElsewhere;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\RunnerConfig;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('names the suites the gate runs no test of, as they serve the application from another process, and what to do', function (): void {
    $observed = Observations::none()->withFiles(ProjectFiles::none()->withRunnerConfig(RunnerConfig::none()->withSuitesElsewhere('Acceptance', 'Api')));

    expect(SuitesElsewhere::in($observed))->toEqual(Findings::of(Finding::of(
        Slug::SuiteServedElsewhere,
        Severity::Advice,
        'The gate runs no test of the suites Acceptance, Api, which serve the application from another process.',
        'A mutant runs in the test\'s own process; a test that drives the application elsewhere judges the original.',
        'Cover what only these suites run with tests in a suite that runs in-process, or leave it out of the trees.',
    )));
});

it('finds nothing where every suite runs in the test\'s own process', function (): void {
    expect(SuitesElsewhere::in(Observations::none()))->toEqual(Findings::none());
});
