<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Psalm\Psalm;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\PsalmProjects;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
    putenv('MUTATION_GATE_CONTRACT_TOKEN');
});

it('refuses a config option that is no path', function (): void {
    expect(Psalm::fromOptions(Configs::options('{"config": 5}'), Scratch::directory()))->toBeInstanceOf(Invalid::class);
});

it('says the configuration its config holds, alike from two roots, with the files it names', function (): void {
    $config = '<psalm errorBaseline="psalm-baseline.xml"><projectFiles><directory name="src"/></projectFiles></psalm>';
    $here = PsalmProjects::project();
    $there = PsalmProjects::project();
    Scratch::write($here, 'psalm.xml', $config);
    Scratch::write($there, 'psalm.xml', $config);
    $settings = PsalmProjects::in($here)->configuration(Withheld::standard());
    $elsewhere = PsalmProjects::in($there)->configuration(Withheld::standard());

    expect($settings)->toEqual($elsewhere)
        ->and($settings instanceof AnalyserSettings ? $settings->references() : $settings)
        ->toEqual(Paths::of(Path::of('psalm.xml'), Path::of('psalm-baseline.xml')));
});

it('reads the dependents a check lists, as its server analyses only what it is sent', function (): void {
    expect(PsalmProjects::in(PsalmProjects::project())->readsDependents())->toBeTrue();
});
