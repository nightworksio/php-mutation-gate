<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Invocations;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

it('is the units of one package one job mutates, what they cost, and its label', function (): void {
    $units = Units::of(Unit::file(Path::of('packages/a/src/Money.php')));
    $package = Package::at(Path::of('packages/a'));
    $cost = Seconds::of(540.0);
    $shard = Shard::of(ShardId::of(2), $package, $units, $cost, 'packages/a/src, part 2 of 4');

    expect($shard->id()->number())->toBe(2)
        ->and($shard->package())->toBe($package)
        ->and($shard->units())->toBe($units)
        ->and($shard->cost())->toBe($cost)
        ->and($shard->label())->toBe('packages/a/src, part 2 of 4')
        ->and($shard->isEmpty())->toBeFalse();
});

it('may be empty, running nothing in the root package and saying so', function (): void {
    $shard = Shard::empty(ShardId::of(3));

    expect($shard->id()->number())->toBe(3)
        ->and($shard->package())->toEqual(Package::at(Path::root()))
        ->and($shard->units())->toEqual(Units::none())
        ->and($shard->cost())->toEqual(Seconds::of(0.0))
        ->and($shard->label())->toBe('nothing to mutate')
        ->and($shard->isEmpty())->toBeTrue();
});

it('runs its units as the invocations they make', function (): void {
    $units = Units::of(Unit::file(Path::of('src/A.php')));

    expect(Shard::of(ShardId::of(1), Package::at(Path::root()), $units, Seconds::of(1.0), 'src')->invocations())
        ->toEqual(Invocations::of($units));
});
