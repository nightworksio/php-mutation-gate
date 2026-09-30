<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\RiskOrder;
use NightWorksIO\MutationGate\Core\Plan\Invocations;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;

it('is the units of one package one job mutates, what they cost, and its label', function (): void {
    $units = Units::of(Unit::file(Path::of('packages/a/src/Money.php')));
    $package = Package::at(Path::of('packages/a'));
    $cost = Seconds::of(540.0);
    $shard = Shard::of(ShardId::of(2), $package, $units, $cost, 'packages/a/src, part 2 of 4');

    expect($shard->id()->number())->toBe(2)
        ->and($shard->package())->toBe($package)
        ->and($shard->units())->toBe($units)
        ->and($shard->cost())->toEqual($cost)
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

it('takes its units in a risk order, keeping the rest of it', function (): void {
    $trees = Trees::of(Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())));
    $order = RiskOrder::of(
        Reach::nothing(Packages::of($trees))->withLines(Path::of('src/B.php'), Lines::of(Line::of(1))),
        Proofs::none()->newest(),
        MutantTriage::under(TimeoutMode::Confirm),
    );
    $units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')));
    $shard = Shard::of(ShardId::of(2), Package::at(Path::root()), $units, Seconds::of(9.0), 'src')->ordered($order);

    expect(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$shard->units()]))->toBe(['src/B.php', 'src/A.php'])
        ->and($shard->id()->number())->toBe(2)
        ->and($shard->cost())->toEqual(Seconds::of(9.0))
        ->and($shard->label())->toBe('src');
});
