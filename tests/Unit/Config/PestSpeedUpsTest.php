<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\PestSpeedUps;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('writes pest.pcovKept as the globs it lists, and writes the config back with the same call', function (): void {
    [$at, $php] = Configs::oneCall(static fn(): Setting => PestSpeedUps::pcovKept('tests/Legacy/**', 'src/Coverage/*.php'), 'pest.pcovKept');

    expect($at)->toBe(['tests/Legacy/**', 'src/Coverage/*.php'])
        ->and($php)->toContain("PestSpeedUps::pcovKept('tests/Legacy/**', 'src/Coverage/*.php')");
});
