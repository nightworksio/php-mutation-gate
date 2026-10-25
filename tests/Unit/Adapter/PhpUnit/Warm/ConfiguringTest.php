<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Configuring;
use PHPUnit\Event\Emitter;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\XmlConfiguration\Loader;

it('builds PHPUnit\'s configuration loader and PHP settings handler for a PHPUnit before 13.4, and for one from it', function (string $version): void {
    $configuring = Configuring::at($version);

    expect([$configuring->loader(), $configuring->handler()])->toEqual([new Loader(), new PhpHandler()]);
})->with(['before 13.4' => ['13.3.6'], 'from 13.4' => ['13.4.0']]);

it('builds them as the PHPUnit installed beside it builds them', function (): void {
    expect(Configuring::installed()->loader())->toBeInstanceOf(Loader::class)
        ->and(Configuring::installed()->handler())->toBeInstanceOf(PhpHandler::class);
});

it('tells the loader and the handler of a PHPUnit from 13.4 where to emit, and those of one before it nothing', function (): void {
    expect(Configuring::at('13.4.0')->told())->toHaveCount(1)
        ->and(Configuring::at('13.4.0')->told()[0])->toBeInstanceOf(Emitter::class)
        ->and(Configuring::at('13.3.6')->told())->toBe([]);
});
