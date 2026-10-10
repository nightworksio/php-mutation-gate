<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Turbo\Platform;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;

it('names the platform the helper is built for from PHP\'s system and processor names', function (string $family, string $machine, Platform $platform): void {
    expect(Platform::of($family, $machine))->toBe($platform);
})->with([
    ['Linux', 'x86_64', Platform::LinuxX64],
    ['Linux', 'aarch64', Platform::LinuxArm64],
    ['Linux', 'arm64', Platform::LinuxArm64],
    ['Darwin', 'x86_64', Platform::MacX64],
    ['Darwin', 'arm64', Platform::MacArm64],
    ['Windows', 'AMD64', Platform::WindowsX64],
]);

it('has none for another system or processor, or for ARM on Windows', function (): void {
    expect(Platform::of('BSD', 'amd64'))->toEqual(NotAccelerated::because(
        'The helper is built for nothing on BSD, so the gate works in its own PHP.',
    ))
        ->and(Platform::of('Plan 9', 'amd64'))->toEqual(NotAccelerated::because(
            'The helper is built for no amd64 on Plan 9, so the gate works in its own PHP.',
        ))
        ->and(Platform::of('Linux', 'riscv64'))->toBeInstanceOf(NotAccelerated::class)
        ->and(Platform::of('Windows', 'ARM64'))->toEqual(NotAccelerated::because(
            'The helper is built for no ARM64 on Windows, so the gate works in its own PHP.',
        ));
});

it('names the binary as each system runs it', function (): void {
    expect(Platform::WindowsX64->binary())->toBe('mutation-gate-turbo.exe')
        ->and(Platform::LinuxX64->binary())->toBe('mutation-gate-turbo')
        ->and(Platform::MacArm64->binary())->toBe('mutation-gate-turbo');
});
