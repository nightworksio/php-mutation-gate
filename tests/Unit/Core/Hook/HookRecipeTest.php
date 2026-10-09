<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hook\HookCall;
use NightWorksIO\MutationGate\Core\Hook\HookFramework;
use NightWorksIO\MutationGate\Core\Hook\HookRecipe;

/** The recipes for a gate Composer linked at this path, from a release it does not name. */
function hookRecipeAt(string $binary): HookRecipe
{
    return HookRecipe::of(HookCall::of(Path::root(), Path::of($binary)), GatePin::unknown());
}

it('writes CaptainHook\'s actions, handing git\'s lines to pre-push through its {$STDIN} placeholder', function (): void {
    $recipe = hookRecipeAt('vendor/bin/mutation-gate');

    expect($recipe->whole(HookFramework::CaptainHook))->toBe(<<<'JSON'
        {
            "pre-push": {
                "enabled": true,
                "actions": [
                    {
                        "action": "vendor/bin/mutation-gate pre-push --stdin={$STDIN}"
                    }
                ]
            },
            "pre-commit": {
                "enabled": true,
                "actions": [
                    {
                        "action": "vendor/bin/mutation-gate pre-commit"
                    }
                ]
            }
        }
        JSON)
        ->and($recipe->addition(HookFramework::CaptainHook))->toBe(<<<'SAID'
            Add to the actions of pre-push:
            {
                "action": "vendor/bin/mutation-gate pre-push --stdin={$STDIN}"
            }
            And to the actions of pre-commit:
            {
                "action": "vendor/bin/mutation-gate pre-commit"
            }
            SAID);
});

it('writes GrumPHP\'s shell task, which runs pre-commit alone', function (): void {
    $recipe = hookRecipeAt('vendor/bin/mutation-gate');

    expect($recipe->whole(HookFramework::GrumPhp))->toBe(<<<'YAML'
        grumphp:
            tasks:
                shell:
                    scripts:
                        - ["-c", "vendor/bin/mutation-gate pre-commit"]
        YAML)
        ->and($recipe->addition(HookFramework::GrumPhp))
        ->toBe('Add to the scripts of the shell task: ["-c", "vendor/bin/mutation-gate pre-commit"]');
});

it('lists the pre-commit framework\'s hooks from the repository, pinned to the gate\'s commit', function (): void {
    $commit = str_repeat('a', 40);
    $installed = Installed::decode(
        Contents::of((string) json_encode(['packages' => [
            ['name' => 'nightworksio/mutation-gate', 'version' => 'v1.2.0', 'source' => ['reference' => $commit]],
        ]])),
        Path::of('vendor/composer/installed.json'),
    );
    $pinned = HookRecipe::of(
        HookCall::of(Path::root(), Path::of('vendor/bin/mutation-gate')),
        $installed instanceof Installed ? GatePin::in($installed) : GatePin::unknown(),
    );

    expect($pinned->whole(HookFramework::PreCommit))->toBe(sprintf(<<<'YAML'
        default_install_hook_types: [pre-commit, pre-push]
        repos:
            - repo: https://github.com/nightworksio/php-mutation-gate
              rev: %s # v1.2.0
              hooks:
                - id: mutation-gate-pre-push
                - id: mutation-gate-pre-commit
        YAML, $commit))
        ->and($pinned->addition(HookFramework::PreCommit))->toStartWith(
            "Add pre-push to default_install_hook_types, and this to the repos:\ndefault_install_hook_types: [pre-commit, pre-push]\n",
        );
});

it('runs the gate by the path the project links it at, where each hook\'s entry names another', function (): void {
    $recipe = hookRecipeAt('bin/my tools/mutation-gate');

    expect($recipe->whole(HookFramework::PreCommit))->toContain(<<<'YAML'
                - id: mutation-gate-pre-push
                  entry: "'bin/my tools/mutation-gate' pre-push"
                - id: mutation-gate-pre-commit
                  entry: "'bin/my tools/mutation-gate' pre-commit"
        YAML)
        ->and($recipe->whole(HookFramework::PreCommit))->toContain('rev: <the commit of a release>')
        ->and($recipe->whole(HookFramework::GrumPhp))->toContain(<<<'YAML'
            - ["-c", "'bin/my tools/mutation-gate' pre-commit"]
            YAML)
        ->and($recipe->whole(HookFramework::CaptainHook))
        ->toContain(<<<'JSON'
            "action": "'bin/my tools/mutation-gate' pre-push --stdin={$STDIN}"
            JSON);
});
