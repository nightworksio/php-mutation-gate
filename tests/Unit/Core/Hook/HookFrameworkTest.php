<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hook\HookFramework;

it('names the files each framework reads its config from', function (): void {
    expect(HookFramework::CaptainHook->files())->toBe(['captainhook.json'])
        ->and(HookFramework::PreCommit->files())->toBe(['.pre-commit-config.yaml'])
        ->and(HookFramework::GrumPhp->files())->toBe([
            'grumphp.yml',
            'grumphp.yaml',
            'grumphp.yml.dist',
            'grumphp.yaml.dist',
            'grumphp.dist.yml',
            'grumphp.dist.yaml',
        ])
        ->and(HookFramework::GrumPhp->file())->toBe('grumphp.yml');
});
