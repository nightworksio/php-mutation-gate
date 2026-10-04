<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/** Settings an analyser dumps in a project at this root, naming its own paths, its temporary directory and its environment. */
function settingsAt(string $root, string $machine): AnalyserSettings
{
    $settings = AnalyserSettings::resolved(sprintf(
        '{"level": 9, "paths": ["%1$s/src"], "rootDir": "%1$s/vendor/phpstan/phpstan", "currentWorkingDirectory": "%1$s",'
        . ' "stubs": ["phar://%1$s/vendor/phpstan/phpstan/phpstan.phar/stubs/core.stub"], "nested": {"b": true, "a": null},'
        . ' "tmpDir": "/tmp/%2$s", "env": {"HOME": "/home/%2$s"}, "empty": []}',
        $root,
        $machine,
    ), $root, 'tmpDir', 'env');

    return $settings instanceof AnalyserSettings ? $settings : throw new LogicException('Unread settings.');
}

it('writes the settings alike from two roots and two machines: paths from the root, keys in order, what differs left out', function (): void {
    $here = settingsAt('/home/ada/project', 'ada');

    expect($here->written())->toBe(settingsAt('/srv/ci/build', 'runner')->written())
        ->and($here->written())->toBe(
            '{"currentWorkingDirectory":".","empty":[],"level":9,"nested":{"a":null,"b":true},"paths":["src"],'
            . '"rootDir":"vendor/phpstan/phpstan","stubs":["phar://vendor/phpstan/phpstan/phpstan.phar/stubs/core.stub"]}',
        );
});

it('keeps a path beside the root, which only shares the start of its name, as it is', function (): void {
    $settings = AnalyserSettings::resolved('{"paths": ["/project-old/src"]}', '/project');

    expect($settings instanceof AnalyserSettings ? $settings->written() : $settings)->toBe('{"paths":["/project-old/src"]}');
});

it('cannot read settings that are no object', function (string $json): void {
    expect(AnalyserSettings::resolved($json, '/p'))->toBeInstanceOf(CannotJudge::class);
})->with(['a list' => ['[1, 2]'], 'a text' => ['"level 9"'], 'no JSON' => ['PHPStan crashed']]);

it('names the files an analyser names from the root, and none for an empty name or a stream', function (): void {
    expect(AnalyserSettings::filesNamed('/p', '/p/phpstan.neon', 'conf/../baseline.neon', '', 'phar:///p/vendor/x.neon', '/etc/stubs/a.stub'))
        ->toEqual(Paths::of(Path::of('phpstan.neon'), Path::of('baseline.neon'), Path::of('/etc/stubs/a.stub')));
});

it('keeps the files it references, each once', function (): void {
    $settings = settingsAt('/p', 'ada')
        ->referencing(Paths::of(Path::of('phpstan.neon'), Path::of('baseline.neon')))
        ->referencing(Paths::of(Path::of('baseline.neon'), Path::of('bootstrap.php')));

    expect($settings->references())->toEqual(Paths::of(Path::of('phpstan.neon'), Path::of('baseline.neon'), Path::of('bootstrap.php')));
});

it('digests the settings with each referenced file\'s contents, whatever order they come in, and a missing one as missing', function (): void {
    $settings = settingsAt('/p', 'ada');
    $files = ByPath::none()
        ->with(Path::of('phpstan.neon'), Digest::sha256Of('config'))
        ->with(Path::of('baseline.neon'), Digest::sha256Of('baseline'));
    $reordered = ByPath::none()
        ->with(Path::of('baseline.neon'), Digest::sha256Of('baseline'))
        ->with(Path::of('phpstan.neon'), Digest::sha256Of('config'));
    $other = static fn(ByPath $with): Digest => $settings->digest($with);

    expect($settings->digest($files))->toEqual($settings->digest($reordered))
        ->and($settings->digest($files))->toEqual(settingsAt('/srv/ci', 'runner')->digest($files))
        ->and($other($files->with(Path::of('baseline.neon'), Digest::sha256Of('baseline, regenerated'))))->not->toEqual($settings->digest($files))
        ->and($other($files->with(Path::of('baseline.neon'), Missing::at(Path::of('baseline.neon')))))->not->toEqual($settings->digest($files))
        ->and($other($files->with(Path::of('stub.php'), Digest::sha256Of('stub'))))->not->toEqual($settings->digest($files))
        ->and($other(ByPath::none()))->not->toEqual($settings->digest($files));
});

it('digests other settings otherwise', function (): void {
    $level8 = AnalyserSettings::resolved('{"level": 8}', '/p');
    $level9 = AnalyserSettings::resolved('{"level": 9}', '/p');

    expect($level8 instanceof AnalyserSettings && $level9 instanceof AnalyserSettings ? $level8->digest(ByPath::none()) : null)
        ->not->toEqual($level9 instanceof AnalyserSettings ? $level9->digest(ByPath::none()) : null);
});
