<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the file --config names, relative to the project or not', function (): void {
    expect(ConfigFile::in('/project', 'ci/gate.json'))->toEqual(Path::of('/project/ci/gate.json'))
        ->and(ConfigFile::in('/project', '/etc/gate.json'))->toEqual(Path::of('/etc/gate.json'));
});

it('finds the one config file in the project', function (string $name): void {
    $project = Scratch::directory();
    Scratch::write($project, $name, '');

    expect(ConfigFile::in($project, ''))->toEqual(Path::of(sprintf('%s/%s', $project, $name)));
})->with(Format::fileNames());

it('is zero-config where there is none', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation.php', '');

    expect(ConfigFile::in($project, ''))->toEqual(Absent::setting());
});

it('refuses two config files, as two answers', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '');
    Scratch::write($project, 'mutation-gate.yml', '');
    Scratch::write($project, 'mutation-gate.neon', '');

    expect(ConfigFile::in($project, ''))->toEqual(CannotJudge::because(
        'More than one config file is here: mutation-gate.json, mutation-gate.yml, mutation-gate.neon. '
        . 'Keep one, or name one with --config.',
    ));
});
