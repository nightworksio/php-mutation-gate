<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('gives nothing it was not given', function (): void {
    $none = Observations::none();

    expect([
        $none->php(),
        $none->runners(),
        $none->settings(),
        $none->trees(),
        $none->gitIgnore(),
        $none->infection(),
        $none->markers(),
        $none->composer(),
        $none->now(),
    ])->toEqual(array_fill(0, 9, NotGiven::value()));
});

it('keeps each part it is given, whatever order they come in', function (): void {
    $php = RunnerPhp::at('/usr/bin/php');
    $runners = InstalledRunners::of(pest: true, infection: false, chosen: false);
    $settings = Configs::settings(['runner' => 'pest']);
    $gitIgnore = GitIgnore::of('/vendor/');
    $infection = InfectionConfig::in('infection.json5', minMsi: true, ignores: true);
    $markers = Markers::of(Marker::of('infection.json5 mutators.global-ignore', 'App\\Money', '{}'));
    $composer = ComposerSetup::of(Paths::of(Path::of('packages/money')));
    $now = new DateTimeImmutable(Configs::NOW);
    $observed = Observations::none()
        ->at($now)
        ->withComposer($composer)
        ->withMarkers($markers)
        ->withInfection($infection)
        ->withGitIgnore($gitIgnore)
        ->withTrees(Trees::none())
        ->withSettings($settings)
        ->withRunners($runners)
        ->withPhp($php);

    expect([$observed->php(), $observed->runners(), $observed->settings(), $observed->trees(), $observed->gitIgnore(), $observed->infection()])
        ->toBe([$php, $runners, $settings, $observed->trees(), $gitIgnore, $infection])
        ->and([$observed->markers(), $observed->composer(), $observed->now()])->toBe([$markers, $composer, $now])
        ->and($observed->trees())->toEqual(Trees::none())
        ->and(Observations::none()->withPhp(CannotJudge::because('no PHP'))->php())->toEqual(CannotJudge::because('no PHP'));
});
