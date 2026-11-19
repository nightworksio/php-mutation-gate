<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Placement;
use NightWorksIO\MutationGate\Core\Config\Definition\ProofsKeys;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Tests\Support\ShippedSchema;

it('writes the schema of each of its keys as the package ships it', function (): void {
    expect(ShippedSchema::written(ProofsKeys::fields(ProjectRoot::origin()), Placement::TopLevel))
        ->toBe(ShippedSchema::holds(['proofs'], Placement::TopLevel));
});
