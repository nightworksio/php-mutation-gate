<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\CoverageKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\Placement;
use NightWorksIO\MutationGate\Tests\Support\ShippedSchema;

it('writes the schema of each of its keys as the package ships it', function (): void {
    expect(ShippedSchema::written(CoverageKeys::fields(), Placement::TopLevel))
        ->toBe(ShippedSchema::holds(['coverage'], Placement::TopLevel));
});
