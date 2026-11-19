<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

it('expects a shard to take its cost as its wall time, spread over the processes where it is a part of the test files', function (int $processes, float $passTwo, float $whole, float $parted): void {
    $shard = Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Unit::file(Path::of('src/A.php'))), Seconds::of(1600.0), 'src');
    $streams = StreamWall::of($processes, $passTwo);

    expect($streams->ofShard($shard))->toEqual(Seconds::of($whole))
        ->and($streams->ofShard($shard->inPart(TestPart::of(1, 76))))->toEqual(Seconds::of($parted));
})->with([
    'four processes' => [4, 1.0, 1600.0, 400.0],
    'a second pass half as long' => [4, 0.5, 1600.0, 200.0],
    'a measured factor of none, held to the least' => [4, 0.0, 1600.0, 20.0],
]);
