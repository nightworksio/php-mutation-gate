<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Diagnosis;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('runs every check over what was observed, what fails a run first', function (): void {
    $observed = Observations::none()
        ->withPhp(RunnerPhp::at('php')->loading('xdebug')->setting('opcache.enable_cli', '1'))
        ->withRunners(InstalledRunners::of(pest: true, infection: true, chosen: false))
        ->withTrees(Trees::none())
        ->withGitIgnore(GitIgnore::of(''))
        ->withInfection(InfectionConfig::in('infection.json5', minMsi: true, ignores: false));

    expect(array_map(static fn(Finding $finding): Slug => $finding->slug(), [...Diagnosis::of($observed)]))->toBe([
        Slug::OpcacheOnTheCommandLine,
        Slug::TwoRunners,
        Slug::NoTree,
        Slug::XdebugSlowsTests,
        Slug::WorkspaceNotIgnored,
        Slug::InfectionConfigToImport,
    ]);
});

it('finds nothing in what nobody observed', function (): void {
    expect(count(Diagnosis::of(Observations::none())))->toBe(0);
});
