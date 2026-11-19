<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Placement;
use NightWorksIO\MutationGate\Core\Config\Definition\TriageKeys;
use NightWorksIO\MutationGate\Tests\Support\ShippedSchema;

it('writes the schema of each of its keys as the package ships it', function (): void {
    expect(ShippedSchema::written(TriageKeys::fields(), Placement::TopLevel))
        ->toBe(ShippedSchema::holds(['budget', 'timeouts', 'flaky', 'tests', 'survivorsFirst'], Placement::TopLevel));
});
