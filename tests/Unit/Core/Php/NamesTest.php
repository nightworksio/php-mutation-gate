<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Names;

it('meets names it shares one with, whatever their case', function (): void {
    expect(Names::of('App\Money', 'App\Clock')->meet(Names::of('app\clock')))->toBeTrue()
        ->and(Names::of('app\money')->meet(Names::of('APP\MONEY', 'App\Other')))->toBeTrue();
});

it('meets no names it shares none with', function (): void {
    expect(Names::of('App\Money')->meet(Names::of('App\Clock')))->toBeFalse()
        ->and(Names::of('App\Money', 'App\Ledger')->meet(Names::of('App\Clock', 'App\Other')))->toBeFalse()
        ->and(Names::of()->meet(Names::of('App\Money')))->toBeFalse();
});

it('meets what either of two merged sets of names meets, and leaves both as they were', function (): void {
    $money = Names::of('App\Money');
    $clock = Names::of('App\Clock');
    $merged = $money->merge($clock);

    expect($merged->meet(Names::of('App\Money')))->toBeTrue()
        ->and($merged->meet(Names::of('App\Clock')))->toBeTrue()
        ->and($money->meet(Names::of('App\Clock')))->toBeFalse()
        ->and($clock->meet(Names::of('App\Money')))->toBeFalse();
});

it('merges any number of sets of names at once, each name once', function (): void {
    $merged = Names::of('App\Money')->merge(Names::of('App\Clock', 'app\money'), Names::of('App\Ledger'));

    expect($merged->all())->toBe(['app\money', 'app\clock', 'app\ledger'])
        ->and(Names::of('App\Money')->merge()->all())->toBe(['app\money']);
});
