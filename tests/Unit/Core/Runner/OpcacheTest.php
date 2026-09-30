<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Opcache;

it('could serve a cached original where opcache is on for the command line or keeps a file cache', function (): void {
    expect(Opcache::of('1', '')->couldServeTheOriginal())->toBeTrue()
        ->and(Opcache::of('On', fileCache: false)->couldServeTheOriginal())->toBeTrue()
        ->and(Opcache::of('0', '/tmp/opcache')->couldServeTheOriginal())->toBeTrue()
        ->and(Opcache::of('0', '')->couldServeTheOriginal())->toBeFalse()
        ->and(Opcache::of(cli: false, fileCache: false)->couldServeTheOriginal())->toBeFalse();
});

it('could serve a cached original from the command line only where opcache is on there', function (): void {
    expect(Opcache::ofCommandLine('1')->couldServeTheOriginal())->toBeTrue()
        ->and(Opcache::ofCommandLine('0')->couldServeTheOriginal())->toBeFalse()
        ->and(Opcache::ofCommandLine(cli: false)->couldServeTheOriginal())->toBeFalse();
});
