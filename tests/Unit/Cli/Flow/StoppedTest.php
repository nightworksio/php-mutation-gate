<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Stopped;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

it('names each unit of the shards that left no result once, though shards of parts of the test files share them', function (): void {
    $shard = static fn(int $number): Shard => Shard::of(
        ShardId::of($number),
        Package::at(Path::root()),
        Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php'))),
        Seconds::of(1.0),
        sprintf('src, part %d of 3', $number),
    )->inPart(TestPart::of($number, 3));
    $stopped = Stopped::among([], [$shard(2), $shard(3)]);

    expect(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$stopped->units()]))
        ->toBe(['src/A.php', 'src/B.php'])
        ->and($stopped->isDoomed())->toBeFalse();
});
