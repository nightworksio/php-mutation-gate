<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Opcache;

it('could serve a cached original where opcache is on for the command line or keeps a file cache', function (): void {
    expect(Opcache::of('1', '')->couldServeTheOriginal())->toBeTrue()
        ->and(Opcache::of('On', fileCache: false)->couldServeTheOriginal())->toBeTrue()
        ->and(Opcache::of('0', '/tmp/opcache')->couldServeTheOriginal())->toBeTrue()
        ->and(Opcache::of('0', '')->couldServeTheOriginal())->toBeFalse()
        ->and(Opcache::of(cli: false, fileCache: false)->couldServeTheOriginal())->toBeFalse()
        ->and(Opcache::current())->toEqual(Opcache::of(ini_get('opcache.enable_cli'), ini_get('opcache.file_cache')));
});
