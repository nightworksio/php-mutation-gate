<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

it('holds the slices in the order they are asked for, one more after them where it takes one', function (): void {
    $first = MapSlice::of(Path::of('shard-1/map.json.gz'), Paths::of(Path::of('src/A.php')));
    $second = MapSlice::of(Path::of('shard-2/map.json.gz'), Paths::none());
    $third = MapSlice::of(Path::of('verdict/map.json.gz'), Paths::of(Path::of('src/A.php'), Path::of('src/B.php')));

    expect([...MapSlices::of($first, $second)->with($third)])->toBe([$first, $second, $third])
        ->and([...MapSlices::of()])->toBe([]);
});
