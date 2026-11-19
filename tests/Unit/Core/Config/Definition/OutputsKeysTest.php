<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\OutputsKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\Placement;
use NightWorksIO\MutationGate\Tests\Support\ShippedSchema;

it('writes the schema of each of its keys as the package ships it', function (): void {
    expect(ShippedSchema::written(OutputsKeys::fields(), Placement::TopLevel))
        ->toBe(ShippedSchema::holds(['outputs'], Placement::TopLevel));
});
