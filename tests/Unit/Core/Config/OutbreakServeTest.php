<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\OutbreakServe;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('snapshots every ten minutes, syncs every second and settles for 50 milliseconds by default', function (): void {
    $serve = OutbreakServe::of();

    expect($serve->snapshotEvery()->seconds())->toBe(600.0)
        ->and($serve->syncEvery()->seconds())->toBe(1.0)
        ->and($serve->debounce()->seconds())->toBe(0.05);
});

it('keeps the intervals a layer sets', function (): void {
    $serve = OutbreakServe::of()->over(OutbreakServe::of(
        snapshotEvery: Seconds::of(30),
        syncEvery: Seconds::of(5),
        debounce: Seconds::milliseconds(200),
    ));

    expect($serve->snapshotEvery()->seconds())->toBe(30.0)
        ->and($serve->syncEvery()->seconds())->toBe(5.0)
        ->and($serve->debounce()->seconds())->toBe(0.2);
});
