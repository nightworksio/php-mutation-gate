<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\ConfigLocation;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the file --config names, relative to the project or not', function (): void {
    expect(ConfigLocation::in('/project', 'ci/gate.json'))->toEqual(Path::of('/project/ci/gate.json'))
        ->and(ConfigLocation::in('/project', '/etc/gate.json'))->toEqual(Path::of('/etc/gate.json'));
});

it('finds the one config file in the project', function (): void {
    foreach (Format::fileNames() as $name) {
        $project = Scratch::directory();
        Scratch::write($project, $name, '');

        expect(ConfigLocation::in($project, NotGiven::value()))->toEqual(Path::of(sprintf('%s/%s', $project, $name)));
    }
});

it('is zero-config where there is none', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation.php', '');

    expect(ConfigLocation::in($project, NotGiven::value()))->toEqual(NoConfigFile::there());
});

it('refuses two config files, as two answers', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '');
    Scratch::write($project, 'mutation-gate.yml', '');
    Scratch::write($project, 'mutation-gate.neon', '');

    expect(ConfigLocation::in($project, NotGiven::value()))->toEqual(CannotJudge::because(
        'More than one config file is here: mutation-gate.json, mutation-gate.yml, mutation-gate.neon. '
        . 'Keep one, or name one with --config.',
    ));
});
