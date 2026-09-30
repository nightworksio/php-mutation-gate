<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Run;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$weighed = static fn(string $path, float $cost): Weighed => Weighed::of(
    Unit::file(Path::of($path)),
    Package::at(Path::of('a')),
    Estimated::of(Seconds::of($cost), CostBasis::Guessed),
);

it('holds its units in the order given, and adds up what they cost', function () use ($weighed): void {
    $run = Run::of($weighed('a/B.php', 1.5), $weighed('a/A.php', 2.25));

    expect(array_map(static fn(Weighed $unit): string => $unit->unit()->path()->value(), iterator_to_array($run, preserve_keys: false)))->toBe(['a/B.php', 'a/A.php'])
        ->and($run)->toHaveCount(2)
        ->and($run->cost())->toEqual(Seconds::of(3.75))
        ->and(Run::of()->cost())->toEqual(Seconds::of(0.0));
});

it('is the shard that mutates it, of its units\' package, or an empty shard where it has no units', function () use ($weighed): void {
    expect(Run::of($weighed('a/A.php', 1.5), $weighed('a/B.php', 2.25))->shard(ShardId::of(3), 'a'))->toEqual(Shard::of(
        ShardId::of(3),
        Package::at(Path::of('a')),
        Units::of(Unit::file(Path::of('a/A.php')), Unit::file(Path::of('a/B.php'))),
        Seconds::of(3.75),
        'a',
    ))
        ->and(Run::of()->shard(ShardId::of(2), 'nothing'))->toEqual(Shard::empty(ShardId::of(2)));
});
