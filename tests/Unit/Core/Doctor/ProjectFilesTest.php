<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\SonarSources;
use NightWorksIO\MutationGate\Core\Doctor\WarmRefusal;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

it('gives no file it was not given', function (): void {
    $none = ProjectFiles::none();

    expect([$none->gitIgnore(), $none->infection(), $none->composer(), $none->runningTheGate(), $none->baseline(), $none->sonarSources(), $none->warmRefusal()])
        ->toEqual(array_fill(0, 7, NotGiven::value()));
});

it('keeps each file it is given, whatever order they come in', function (): void {
    $gitIgnore = GitIgnore::of('/vendor/');
    $infection = InfectionConfig::in('infection.json5', minMsi: true, ignores: false);
    $composer = ComposerSetup::of(Paths::of(Path::of('packages/money')));
    $running = Paths::of(Path::of('.github/workflows/mutation.yml'));
    $baseline = Baseline::none();
    $sonar = SonarSources::of(Path::of('src'));
    $warm = WarmRefusal::of('The boot left 1 socket open once bootstrap.php ran.');
    $files = ProjectFiles::none()
        ->withWarmRefusal($warm)
        ->withSonarSources($sonar)
        ->withBaseline($baseline)
        ->withRunningTheGate($running)
        ->withComposer($composer)
        ->withInfection($infection)
        ->withGitIgnore($gitIgnore);

    expect([$files->gitIgnore(), $files->infection(), $files->composer(), $files->runningTheGate(), $files->baseline(), $files->sonarSources(), $files->warmRefusal()])
        ->toBe([$gitIgnore, $infection, $composer, $running, $baseline, $sonar, $warm])
        ->and(ProjectFiles::none()->withBaseline(CannotJudge::because('unreadable'))->baseline())->toEqual(CannotJudge::because('unreadable'));
});
