<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;

it('holds an Infection config\'s file, and whether it sets a minMsi or ignores mutants itself', function (): void {
    $config = InfectionConfig::in('infection.json5', minMsi: true, ignores: false);

    expect([$config->file(), $config->setsMinMsi(), $config->ignores()])->toBe(['infection.json5', true, false]);
});
