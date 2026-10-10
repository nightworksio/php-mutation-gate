<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Turbo\Installation;
use NightWorksIO\MutationGate\Adapter\Turbo\Pinned;
use NightWorksIO\MutationGate\Adapter\Turbo\Platform;
use NightWorksIO\MutationGate\Adapter\Turbo\Sidecar;
use NightWorksIO\MutationGate\Adapter\Turbo\Unavailable;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Protocol;
use NightWorksIO\MutationGate\Core\Turbo\Request;
use NightWorksIO\MutationGate\Port\Accelerator;
use NightWorksIO\MutationGate\Tests\Fakes\ProcessesFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$agreeing = static fn(): ProcessesFake => new ProcessesFake(static fn(): Ran => Ran::exited(0, (string) json_encode([
    'name' => Protocol::HELPER,
    'protocol' => Protocol::VERSION,
    'version' => Protocol::HELPER_VERSION,
])));

$found = static fn(
    Variables $variables,
    string $vendor,
    ProcessesFake $processes,
    Platform|NotAccelerated $platform = Platform::LinuxX64,
    Pinned|NotGiven $pins = new NotGiven(),
): Accelerator => Installation::found(
    $variables,
    $vendor,
    Scratch::directory(),
    $processes,
    Environment::none(),
    $platform,
    $pins instanceof Pinned ? $pins : Pinned::of([]),
);

$why = static function (Accelerator $accelerator): string {
    $answer = $accelerator->answer(Request::ofText('{}'));

    return $answer instanceof NotAccelerated ? $answer->why() : '';
};

/** A vendor directory holding the package's binary for Linux on x86-64, by its path. */
$installed = static function (string $binary = 'the helper'): string {
    $vendor = Scratch::directory();
    Scratch::write($vendor, 'nightworksio/mutation-gate-turbo/bin/linux-x86_64/mutation-gate-turbo', $binary);

    return $vendor;
};

it('is off where MUTATION_GATE_TURBO says off, and runs nothing', function () use ($found, $agreeing, $why, $installed): void {
    $processes = $agreeing();
    $accelerator = $found(Variables::of(['MUTATION_GATE_TURBO' => 'off', 'MUTATION_GATE_TURBO_BINARY' => '/opt/helper']), $installed(), $processes);

    expect($accelerator)->toBeInstanceOf(Unavailable::class)
        ->and($why($accelerator))->toBe('MUTATION_GATE_TURBO=off turns the helper off.')
        ->and($processes->ran())->toBe([]);
});

it('runs the binary MUTATION_GATE_TURBO_BINARY names unpinned, once it agrees in its handshake', function () use ($found, $agreeing): void {
    $processes = $agreeing();

    expect($found(Variables::of(['MUTATION_GATE_TURBO_BINARY' => '/opt/helper']), Scratch::directory(), $processes, NotAccelerated::because('none')))
        ->toBeInstanceOf(Sidecar::class)
        ->and([...$processes->ran()[0]->arguments()])->toBe(['/opt/helper', 'handshake'])
        ->and($processes->ran()[0]->deadline())->toEqual(Seconds::of(10.0));
});

it('refuses a helper whose handshake disagrees or fails', function () use ($found, $why): void {
    $other = new ProcessesFake(static fn(): Ran => Ran::exited(0, '{"name":"other","version":"9","protocol":1}'));
    $failing = new ProcessesFake(static fn(): Ran => Ran::exited(127, 'not found', ''));

    expect($found(Variables::of(['MUTATION_GATE_TURBO_BINARY' => '/opt/helper']), '', $other))->toBeInstanceOf(Unavailable::class)
        ->and($why($found(Variables::of(['MUTATION_GATE_TURBO_BINARY' => '/opt/helper']), '', $failing)))
        ->toBe('The helper at /opt/helper did not say what it is: exit 127: not found');
});

it('has none on a platform the helper is not built for', function () use ($found, $agreeing, $why): void {
    expect($why($found(Variables::of([]), Scratch::directory(), $agreeing(), NotAccelerated::because('no build for riscv64'))))
        ->toBe('no build for riscv64');
});

it('has none where the package is not installed', function () use ($found, $agreeing, $why): void {
    expect($why($found(Variables::of([]), Scratch::directory(), $agreeing())))
        ->toBe('The helper is not installed: composer require --dev nightworksio/mutation-gate-turbo adds it.');
});

it('runs no installed binary this gate pins no build of', function () use ($found, $agreeing, $why, $installed): void {
    $processes = $agreeing();

    expect($why($found(Variables::of([]), $installed(), $processes)))->toBe('This gate pins no build of the helper for linux-x86_64, so it runs none.')
        ->and($processes->ran())->toBe([]);
});

it('runs the installed binary only where its SHA-256 is the pinned one', function () use ($found, $agreeing, $why, $installed): void {
    $vendor = $installed('the helper');
    $binary = sprintf('%s/nightworksio/mutation-gate-turbo/bin/linux-x86_64/mutation-gate-turbo', $vendor);
    $pinned = Pinned::of(['linux-x86_64' => hash('sha256', 'the helper')]);
    $other = Pinned::of(['linux-x86_64' => hash('sha256', 'another build')]);
    $processes = $agreeing();

    expect($found(Variables::of([]), $vendor, $processes, Platform::LinuxX64, $pinned))->toBeInstanceOf(Sidecar::class)
        ->and([...$processes->ran()[0]->arguments()])->toBe([$binary, 'handshake'])
        ->and($why($found(Variables::of([]), $vendor, $agreeing(), Platform::LinuxX64, $other)))->toBe(sprintf(
            'The helper at %s is not the build this gate pins: its SHA-256 is %s, and the pin is %s.',
            $binary,
            hash('sha256', 'the helper'),
            hash('sha256', 'another build'),
        ));
});
