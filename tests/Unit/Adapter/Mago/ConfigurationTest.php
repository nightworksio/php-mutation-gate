<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\Configuration;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

/** What `mago config` prints for a workspace at this root, with a baseline, included and patched sources, and other commands' sections. */
function magoShownAt(string $root, int $width): ChildProcess
{
    return ChildProcess::exited(0, sprintf(
        '{"threads": 1, "php-version": "8.5.0", "source": {"workspace": "%1$s", "paths": ["src"], "includes": ["vendor/acme"],'
        . ' "patches": ["patches/acme.php"], "excludes": []}, "analyzer": {"baseline": "mago-baseline.toml", "find-unused-expressions": true},'
        . ' "linter": {"rules": {}}, "formatter": {"print-width": %2$d}, "guard": {"mode": "strict"}}',
        $root,
        $width,
    ), '');
}

it('says the configuration Mago merges, alike from two roots, without its other commands\' sections, with the files it names', function (): void {
    $here = Configuration::shown(magoShownAt('/home/ada/project', 120), Root::of('/home/ada/project'), '/home/ada/project/mago.toml');
    $there = Configuration::shown(magoShownAt('/srv/ci/build', 80), Root::of('/srv/ci/build'), '/srv/ci/build/mago.toml');

    expect($here instanceof AnalyserSettings ? $here->written() : $here)->toBe($there instanceof AnalyserSettings ? $there->written() : $there)
        ->and($here instanceof AnalyserSettings ? $here->written() : '')->not->toContain('print-width')
        ->and($here instanceof AnalyserSettings ? $here->references() : $here)->toEqual(Paths::of(
            Path::of('mago.toml'),
            Path::of('mago-baseline.toml'),
            Path::of('vendor/acme'),
            Path::of('patches/acme.php'),
        ));
});

it('names no baseline Mago is not told of, and no config file where it reads none', function (): void {
    $settings = Configuration::shown(ChildProcess::exited(0, '{"analyzer": {"baseline": null}, "source": {}}', ''), Root::of('/p'));

    expect($settings instanceof AnalyserSettings ? $settings->references() : $settings)->toEqual(Paths::none());
});

it('cannot say the configuration where Mago cannot print it', function (ChildProcess|CannotJudge $shown, string $why): void {
    expect(Configuration::shown($shown, Root::of('/p')))->toEqual(CannotJudge::because($why));
})->with([
    'a failed command' => [ChildProcess::exited(2, '', 'unknown command'), 'Mago could not say the configuration it runs with (exit 2: unknown command).'],
    'a binary not downloaded' => [CannotJudge::because('No binary.'), 'Mago could not say the configuration it runs with (No binary.).'],
    'output that is no object' => [ChildProcess::exited(0, 'Mago panicked', ''), 'The analyser\'s resolved configuration is no object: null'],
]);
