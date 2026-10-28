<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Owner\OwnerFloor;
use NightWorksIO\MutationGate\Core\Owner\OwnerFloors;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Tests\Support\Owners;

it('keeps one floor to an owner, the last written, in the order of the owners\' names', function (): void {
    $floors = OwnerFloors::of(
        OwnerFloor::of(Owners::owner('@jo'), Floor::of(10)),
        OwnerFloor::of(Owners::owner('@acme/web'), Floor::of(20)),
        OwnerFloor::of(Owners::owner('@jo'), Floor::of(30)),
    );

    expect(array_map(static fn(OwnerFloor $floor): array => [$floor->owner()->value(), $floor->floor()], [...$floors]))
        ->toEqual([['@acme/web', Floor::of(20)], ['@jo', Floor::of(30)]])
        ->and([...OwnerFloors::none()])->toBe([]);
});
