<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\PhpIni;

it('sets Xdebug\'s variable to the mode its settings name, over the one the environment has', function (): void {
    expect(PhpIni::of(['xdebug.mode' => 'off'], turbo: false)->environment(['XDEBUG_MODE' => 'coverage', 'HOME' => '/home/ci']))
        ->toBe(['XDEBUG_MODE' => 'off', 'HOME' => '/home/ci']);
});

it('leaves the environment as it is where its settings name no mode', function (): void {
    expect(PhpIni::of(['pcov.enabled' => false], turbo: false)->environment(['XDEBUG_MODE' => 'coverage']))
        ->toBe(['XDEBUG_MODE' => 'coverage'])
        ->and(PhpIni::none()->environment([]))->toBe([]);
});

it('passes each setting as a -d argument, spelling booleans as PHP does', function (): void {
    expect(PhpIni::of(['pcov.enabled' => false, 'opcache.enable_cli' => true, 'xdebug.mode' => 'off'], turbo: false)->arguments('/project', '/tmp/cache'))
        ->toBe(['-dpcov.enabled=0', '-dopcache.enable_cli=1', '-dxdebug.mode=off']);
});
