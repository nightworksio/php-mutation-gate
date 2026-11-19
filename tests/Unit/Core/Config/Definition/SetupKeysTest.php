<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Placement;
use NightWorksIO\MutationGate\Core\Config\Definition\SetupKeys;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Tests\Support\ShippedSchema;

it('writes the schema of each of its keys as the package ships it', function (Placement $placement): void {
    expect(ShippedSchema::written(SetupKeys::fields(ProjectRoot::origin(), $placement), $placement))
        ->toBe(ShippedSchema::holds(['$schema', 'extensions', 'preset', 'runner', 'treeSource'], $placement));
})->with(['at the top' => [Placement::TopLevel], 'in a context block' => [Placement::ContextBlock]]);
