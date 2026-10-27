<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\ConfiguredOwnerFloors;
use NightWorksIO\MutationGate\Core\Config\Table;
use NightWorksIO\MutationGate\Core\Owner\OwnerFloor;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

it('holds each owner a rule can write to its floor, and warns of each key that is none', function (): void {
    $floors = Table::row('@acme/payments', 90)
        ->merged(Table::row('payments', 80))
        ->merged(Table::row('jo@acme.example', 75.5));

    expect(array_map(
        static fn(OwnerFloor $floor): array => [$floor->owner()->value(), $floor->floor()],
        [...ConfiguredOwnerFloors::of($floors)],
    ))->toEqual([['@acme/payments', Floor::of(90)], ['jo@acme.example', Floor::of(75.5)]])
        ->and(ConfiguredOwnerFloors::unnamed($floors))->toEqual(Warnings::of(Warning::that(
            'owners.floors names payments, which no CODEOWNERS rule can name as an owner, so it holds no code to its floor.',
        )))
        ->and(ConfiguredOwnerFloors::unnamed(Table::none()))->toEqual(Warnings::none());
});
