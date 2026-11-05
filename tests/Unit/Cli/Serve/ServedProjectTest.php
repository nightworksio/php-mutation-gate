<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\InstalledGate;
use NightWorksIO\MutationGate\Cli\Serve\ServedProject;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('identifies a project by its real root, the gate\'s version, and the digests of its lock and the config file found', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.lock', '{"packages": []}');
    Scratch::write($project, 'mutation-gate.json', '{}');
    Scratch::write($project, 'other.json', '{"preset": "library"}');
    $found = ServedProject::identity($project, NotGiven::value());
    $named = ServedProject::identity($project, 'other.json');

    expect($found->written()->line())->toBe((string) json_encode([
        'root' => realpath($project),
        'version' => InstalledGate::version()->spelt(),
        'lock' => Digest::sha256Of('{"packages": []}')->value(),
        'config' => Digest::sha256Of('{}')->value(),
    ], JSON_UNESCAPED_SLASHES))
        ->and($named->changedSince($found))->toBe('The project\'s config');
});

it('identifies a project with no lock or config by empty digests, and a root that is not there by its path', function (): void {
    $identity = ServedProject::identity('/no/such/project', NotGiven::value());

    expect($identity->written()->line())->toContain('"root":"/no/such/project"')
        ->and($identity->written()->line())->toContain('"lock":"","config":""');
});
