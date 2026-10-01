<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Headroom;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;

it('is left by a cap of at least twice what the suite held, and always by no cap', function (): void {
    $room = Headroom::standard();

    expect($room->isLeftBy(MemoryCap::standard(), MemoryCap::of(512, MemoryUnit::Megabytes)))->toBeTrue()
        ->and($room->isLeftBy(MemoryCap::standard(), MemoryCap::of(513, MemoryUnit::Megabytes)))->toBeFalse()
        ->and($room->isLeftBy(MemoryCap::none(), MemoryCap::of(8, MemoryUnit::Gigabytes)))->toBeTrue()
        ->and($room->neededFor(MemoryCap::of(600, MemoryUnit::Megabytes))->written())->toBe('1200M');
});
