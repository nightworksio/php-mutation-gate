<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedger;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedgers;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('gives nothing it was not given', function (): void {
    $none = Observations::none();

    expect([$none->php(), $none->runners(), $none->settings(), $none->trees(), $none->markers(), $none->ledgers(), $none->now()])
        ->toEqual(array_fill(0, 7, NotGiven::value()))
        ->and($none->files())->toEqual(ProjectFiles::none());
});

it('keeps each part it is given, whatever order they come in', function (): void {
    $php = RunnerPhp::at('/usr/bin/php');
    $runners = InstalledRunners::of(pest: true, infection: false, chosen: false);
    $settings = Configs::settings(['runner' => 'pest']);
    $markers = Markers::of(Marker::of('infection.json5 mutators.global-ignore', 'App\\Money', '{}'));
    $files = ProjectFiles::none()->withGitIgnore(GitIgnore::of('/vendor/'));
    $ledgers = KeptLedgers::of(KeptLedger::of(Path::of('.mutation-gate/ledger/refs/heads/main/ledger.json.gz'), 120));
    $now = new DateTimeImmutable(Configs::NOW);
    $observed = Observations::none()
        ->at($now)
        ->withLedgers($ledgers)
        ->withFiles($files)
        ->withMarkers($markers)
        ->withTrees(Trees::none())
        ->withSettings($settings)
        ->withRunners($runners)
        ->withPhp($php);

    expect([$observed->php(), $observed->runners(), $observed->settings(), $observed->markers(), $observed->files(), $observed->ledgers(), $observed->now()])
        ->toBe([$php, $runners, $settings, $markers, $files, $ledgers, $now])
        ->and($observed->trees())->toEqual(Trees::none())
        ->and(Observations::none()->withPhp(CannotJudge::because('no PHP'))->php())->toEqual(CannotJudge::because('no PHP'));
});
