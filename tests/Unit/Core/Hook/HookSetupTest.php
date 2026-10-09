<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hook\HookFramework;
use NightWorksIO\MutationGate\Core\Hook\HookSetup;
use NightWorksIO\MutationGate\Core\NotGiven;

it('names each place init --hook sets hooks up, and the framework each is', function (): void {
    expect(HookSetup::names())->toBe('git|captainhook|grumphp|pre-commit|none')
        ->and(array_map(static fn(HookSetup $setup): HookFramework|NotGiven => $setup->framework(), HookSetup::cases()))
        ->toEqual([
            NotGiven::value(),
            HookFramework::CaptainHook,
            HookFramework::GrumPhp,
            HookFramework::PreCommit,
            NotGiven::value(),
        ]);
});
