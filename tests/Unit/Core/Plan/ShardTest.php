<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

it('is the units one job mutates', function (): void {
    $units = Units::of(Unit::file(Path::of('src/Money.php')));
    $shard = Shard::of(ShardId::of(2), $units);

    expect($shard->id()->number())->toBe(2)
        ->and($shard->units())->toBe($units);
});
