<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\Diagnosis;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedger;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedgers;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('runs every check over what was observed, what fails a run first', function (): void {
    $observed = Observations::none()
        ->withPhp(RunnerPhp::at('php')->loading('xdebug')->setting('opcache.enable_cli', '1'))
        ->withRunners(InstalledRunners::of(pest: true, infection: true, chosen: false))
        ->withTrees(Trees::of(Tree::at(Path::of('packages/money/src'), Undeclared::floor(), Package::at(Path::root()))))
        ->withFiles(ProjectFiles::none()
            ->withGitIgnore(GitIgnore::of(''))
            ->withInfection(InfectionConfig::in('infection.json5', minMsi: true, ignores: false))
            ->withComposer(ComposerSetup::of(Paths::of(Path::of('packages/money'))))
            ->withRunningTheGate(Paths::of(Path::of('.gitlab-ci.yml')))
            ->withBaseline(Baseline::none()))
        ->withLedgers(KeptLedgers::of(KeptLedger::of(Path::of('ledger.json.gz'), 30_000_000)))
        ->withSettings(Configs::settings([
            'runner' => 'pest',
            'ignores' => ['entries' => [['path' => 'src/A.php', 'mutator' => 'Plus', 'reason' => 'Equivalent', 'expires' => '2026-10-01']]],
        ]))
        ->withMarkers(Markers::of(Marker::of('infection.json5 mutators.global-ignore', 'App\\Money', '{}')))
        ->in(DoctorRun::of(new DateTimeImmutable(Configs::NOW), -1));

    expect(array_map(static fn(Finding $finding): Slug => $finding->slug(), [...Diagnosis::of($observed)]))->toBe([
        Slug::OpcacheOnTheCommandLine,
        Slug::TwoRunners,
        Slug::TreeWithoutFloor,
        Slug::NativeMarkersRefused,
        Slug::MirroredPathRepository,
        Slug::XdebugSlowsTests,
        Slug::LedgerTooLarge,
        Slug::WorkspaceNotIgnored,
        Slug::IgnoresExpiring,
        Slug::InfectionConfigToImport,
    ]);
});

it('finds nothing in what nobody observed', function (): void {
    expect(count(Diagnosis::of(Observations::none())))->toBe(0);
});
