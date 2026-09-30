<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\LoweredFloor;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;

it('holds a tree, the floor it went down from and to, and that no reason was given', function (): void {
    $floor = LoweredFloor::of(Path::of('lib'), Floor::of(65), Floor::of(60), Unlowered::floor());

    expect($floor->tree())->toEqual(Path::of('lib'))
        ->and($floor->from())->toEqual(Floor::of(65))
        ->and($floor->to())->toEqual(Floor::of(60))
        ->and($floor->lowering())->toEqual(Unlowered::floor());
});
