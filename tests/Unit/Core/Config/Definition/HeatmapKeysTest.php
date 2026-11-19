<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\HeatmapKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\Placement;
use NightWorksIO\MutationGate\Tests\Support\ShippedSchema;

it('writes the schema of each of its keys as the package ships it', function (): void {
    expect(ShippedSchema::written(HeatmapKeys::fields(), Placement::TopLevel))
        ->toBe(ShippedSchema::holds(['heatmap'], Placement::TopLevel));
});
