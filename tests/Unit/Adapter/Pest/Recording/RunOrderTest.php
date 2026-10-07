<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RunOrder;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Test\TestId;

it('places a killer that is the test last started at how many tests started, with the digest of their order', function (): void {
    $order = new RunOrder(41);
    $order->started('T::adds');
    $order->started('T::drains');

    expect($order->placed('T::drains')->fields())->toBe([
        'at' => 2,
        'order' => OrderDigest::of(TestId::of('T::adds'), TestId::of('T::drains'))->value(),
        'run' => 41,
    ]);
});

it('places no killer that is not the test last started, as a class whose set-up failed, nor one before any test started', function (): void {
    $order = new RunOrder(41);
    $none = $order->placed('T::adds')->fields();
    $unnamed = $order->placed('')->fields();
    $order->started('T::adds');

    expect($none)->toBe(['run' => 41])
        ->and($unnamed)->toBe(['run' => 41])
        ->and($order->placed('T')->fields())->toBe(['run' => 41])
        ->and(Placed::unplaced(7)->fields())->toBe(['run' => 7])
        ->and(Placed::at(3, 'abc', 7)->fields())->toBe(['at' => 3, 'order' => 'abc', 'run' => 7]);
});
