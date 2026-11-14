<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\MapFileRead;
use NightWorksIO\MutationGate\Core\Coverage\VouchedRead;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\VouchedMaps;

it('builds the map the careful reading builds of a text it could vouch for', function (): void {
    foreach (VouchedMaps::texts() as $name => [$json]) {
        expect(VouchedRead::read($json))->toEqual(MapFileRead::read(Node::decode($json)), $name)
            ->and(VouchedMaps::written(VouchedRead::read($json)))->toBe(VouchedMaps::carefully($json), $name);
    }
});
