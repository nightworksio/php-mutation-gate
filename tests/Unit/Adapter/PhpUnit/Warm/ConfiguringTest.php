<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Configuring;
use PHPUnit\Event\Emitter;
use PHPUnit\Event\Facade;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\XmlConfiguration\Loader;

it('builds PHPUnit\'s configuration loader and PHP settings handler from 13.4 told an emitter of their own, not PHPUnit\'s facade', function (): void {
    $configuring = Configuring::at('13.4.0');

    foreach ([$configuring->loader(), $configuring->handler()] as $built) {
        $emitter = (fn(): Emitter => $this->emitter)->call($built);

        expect($emitter)->toBeInstanceOf(Emitter::class)
            ->and($emitter === Facade::emitter())->toBeFalse();
    }
});

it('builds them as the PHPUnit installed beside it builds them', function (): void {
    expect(Configuring::installed()->loader())->toBeInstanceOf(Loader::class)
        ->and(Configuring::installed()->handler())->toBeInstanceOf(PhpHandler::class);
});

it('tells the loader and the handler of a PHPUnit from 13.4 where to emit, and those of one before it nothing', function (): void {
    expect(Configuring::at('13.4.0')->told())->toHaveCount(1)
        ->and(Configuring::at('13.4.0')->told()[0])->toBeInstanceOf(Emitter::class)
        ->and(Configuring::at('13.3.6')->told())->toBe([]);
});
