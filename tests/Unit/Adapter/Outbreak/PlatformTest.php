<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Platform;
use NightWorksIO\MutationGate\Core\NotGiven;

it('names a platform a binary is built for as its directory is named, whatever names its system gives it', function (string $family, string $machine, string $name, bool $windows): void {
    $platform = Platform::of($family, $machine);

    expect($platform)->toBeInstanceOf(Platform::class)
        ->and($platform instanceof Platform ? $platform->name() : '')->toBe($name)
        ->and($platform instanceof Platform && $platform->isWindows())->toBe($windows);
})->with([
    'Linux on x86_64' => ['Linux', 'x86_64', 'linux-x86_64', false],
    'Linux on aarch64' => ['Linux', 'aarch64', 'linux-aarch64', false],
    'macOS on arm64' => ['Darwin', 'arm64', 'darwin-aarch64', false],
    'macOS on x86_64' => ['Darwin', 'x86_64', 'darwin-x86_64', false],
    'Windows on AMD64' => ['Windows', 'AMD64', 'windows-x86_64', true],
]);

it('has no platform for a system or a machine no binary is built for', function (string $family, string $machine): void {
    expect(Platform::of($family, $machine))->toEqual(NotGiven::value());
})->with([
    'BSD' => ['BSD', 'x86_64'],
    'a 32-bit machine' => ['Linux', 'i686'],
]);
