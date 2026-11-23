<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\PackageWork;
use NightWorksIO\MutationGate\Core\Plan\ShardOverheads;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/** A package whose one unit costs this many seconds. */
function overheadPackage(float $cost): PackageWork
{
    return PackageWork::of(Weighed::of(
        Unit::file(Path::of('src/A.php')),
        Package::at(Path::root()),
        Estimated::of(Seconds::of($cost), CostBasis::Guessed),
    ));
}

// A shard's wall second holds two seconds of the cost, each job starts with twenty
// seconds of overhead, and shards.cutAt holds no count back. Ten minutes of cost bill
// six minutes in one job, in two and in three, and eight in four.
$billed = static fn(): ShardOverheads => ShardOverheads::of(Seconds::of(20.0), 0.0, 2.0);

it('raises a count to the most shards that bill no more whole minutes, each job\'s time rounded up', function () use ($billed): void {
    expect($billed()->fewestMinutes([overheadPackage(600.0)], [1], 4))->toBe([3])
        ->and($billed()->fewestMinutes([overheadPackage(600.0)], [1], 3))->toBe([3])
        ->and($billed()->fewestMinutes([overheadPackage(600.0)], [1], 2))->toBe([2]);
});

it('prices a job at its share of the cost over the seconds of it a wall second holds', function (): void {
    expect(ShardOverheads::of(Seconds::none(), 0.0, 2.0)->fewestMinutes([overheadPackage(120.0)], [1], 2))->toBe([1]);
});

it('raises from the count a package has, and from one where it has none', function () use ($billed): void {
    expect($billed()->fewestMinutes([overheadPackage(600.0)], [2], 3))->toBe([3])
        ->and($billed()->fewestMinutes([overheadPackage(600.0)], [0], 2))->toBe([3])
        ->and($billed()->fewestMinutes([overheadPackage(120.0)], [0], 0))->toBe([0]);
});

it('gives a later package only the room the packages before it left', function () use ($billed): void {
    expect($billed()->fewestMinutes([overheadPackage(600.0), overheadPackage(600.0)], [1, 1], 5))->toBe([3, 2])
        ->and($billed()->fewestMinutes([overheadPackage(600.0), overheadPackage(600.0)], [1, 1], 4))->toBe([3, 1]);
});

it('raises no count where the counts already come to the most there may be, or to more', function () use ($billed): void {
    expect($billed()->fewestMinutes([overheadPackage(600.0)], [1], 1))->toBe([1])
        ->and($billed()->fewestMinutes([overheadPackage(600.0), overheadPackage(600.0)], [3, 1], 1))->toBe([3, 1]);
});
