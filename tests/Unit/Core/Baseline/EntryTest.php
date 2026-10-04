<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;

it('is a path and the floor it achieved, never lowered to begin with', function (): void {
    $entry = Entry::of(Path::of('app/Http'), Floor::of(83.41));

    expect($entry->path())->toEqual(Path::of('app/Http'))
        ->and($entry->floor())->toEqual(Floor::of(83.41))
        ->and($entry->lowering())->toEqual(Unlowered::floor());
});

it('carries why its floor went down, and from what', function (): void {
    $lowered = Lowered::from(Floor::of(64.5), 'The export feature and its tests were removed together');
    $entry = Entry::of(Path::of('app/Legacy'), Floor::of(61.2))->lowered($lowered);

    expect($entry->lowering())->toBe($lowered)
        ->and($entry->floor())->toEqual(Floor::of(61.2))
        ->and($entry->path())->toEqual(Path::of('app/Legacy'))
        ->and($lowered->floor())->toEqual(Floor::of(64.5))
        ->and($lowered->reason())->toBe('The export feature and its tests were removed together');
});
