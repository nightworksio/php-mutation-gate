<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Extension\ConfigLoaderContract;
use NightWorksIO\MutationGate\Tests\Fakes\ConfigLoaderFake;

it('says each way a loader breaks the contract', function (): void {
    $readsNothing = new ConfigLoaderFake([
        '/fixtures/Config/valid.toml' => '{}',
        '/fixtures/Config/invalid.toml' => '{}',
        '/fixtures/Config/missing.toml' => '{}',
    ]);

    expect([...ConfigLoaderContract::failures($readsNothing, Path::of('/fixtures/Config'), 'toml')])->toBe([
        '/fixtures/Config/valid.toml, in a project at /fixtures/Config: the loader reads {}; the gate reads '
        . '{"runner":"pest","trees":[{"path":"src","floor":100}],"newCode":{"floor":100}}',
        '/fixtures/Config/valid.toml, in a project at /fixtures: the loader reads {}; the gate reads '
        . '{"runner":"pest","trees":[{"path":"Config/src","floor":100}],"newCode":{"floor":100}}',
        '/fixtures/Config/invalid.toml, in a project at /fixtures/Config: the loader reads {}; the gate reads '
        . 'newCode.floor: expected a number from 0 to 100, got 120',
        '/fixtures/Config/missing.toml is not there, and was read anyway.',
    ]);
});

it('says what a loader could not judge', function (): void {
    $failures = [...ConfigLoaderContract::failures(new ConfigLoaderFake([]), Path::of('/fixtures/Config'), 'toml')];

    expect($failures)->toHaveCount(3)
        ->and($failures[2])->toBe(
            '/fixtures/Config/invalid.toml, in a project at /fixtures/Config: the loader reads nothing it can judge '
            . '(/fixtures/Config/invalid.toml is not there.); the gate reads '
            . 'newCode.floor: expected a number from 0 to 100, got 120',
        );
});
