<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\HookFrameworkFiles;
use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hook\HookCall;
use NightWorksIO\MutationGate\Core\Hook\HookFramework;
use NightWorksIO\MutationGate\Core\Hook\HookRecipe;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The recipes for the gate Composer links at `vendor/bin`, from a release it does not name. */
function hookFrameworkRecipe(): HookRecipe
{
    return HookRecipe::of(HookCall::of(Path::root(), Path::of('vendor/bin/mutation-gate')), GatePin::unknown());
}

it('writes the manager\'s config where it reads none, and says how to install its hooks', function (
    HookFramework $manager,
    string $next,
): void {
    $project = Scratch::directory();

    expect(HookFrameworkFiles::made($project, $manager, GatePin::unknown(), Output::Written))
        ->toBe(sprintf("Wrote %s.\n%s", $manager->file(), $next))
        ->and((string) file_get_contents(sprintf('%s/%s', $project, $manager->file())))
        ->toBe(sprintf("%s\n", hookFrameworkRecipe()->whole($manager)));
})->with([
    'CaptainHook' => [HookFramework::CaptainHook, 'Then install its hooks: vendor/bin/captainhook install'],
    'GrumPHP' => [
        HookFramework::GrumPhp,
        "Then install its hooks: vendor/bin/grumphp git:init\nGrumPHP runs no pre-push hook; vendor/bin/mutation-gate hook install adds the gate's.",
    ],
    'the pre-commit framework' => [HookFramework::PreCommit, 'Then install its hooks: pre-commit install'],
]);

it('prints what to add to a config the manager reads already, and leaves it as it is', function (
    HookFramework $manager,
    string $there,
): void {
    $project = Scratch::directory();
    Scratch::write($project, $there, "ours\n");

    $made = HookFrameworkFiles::made($project, $manager, GatePin::unknown(), Output::Written);

    expect($made)->toStartWith(sprintf(
        "%s is here, so init leaves it as it is. %s\nThen install its hooks: ",
        $there,
        hookFrameworkRecipe()->addition($manager),
    ))->and((string) file_get_contents(sprintf('%s/%s', $project, $there)))->toBe("ours\n")
        ->and(is_file(sprintf('%s/%s', $project, $manager->file())))->toBe($there === $manager->file());
})->with([
    'captainhook.json' => [HookFramework::CaptainHook, 'captainhook.json'],
    'grumphp.yml' => [HookFramework::GrumPhp, 'grumphp.yml'],
    'grumphp.dist.yaml' => [HookFramework::GrumPhp, 'grumphp.dist.yaml'],
    '.pre-commit-config.yaml' => [HookFramework::PreCommit, '.pre-commit-config.yaml'],
]);

it('prints the config whole with --stdout, and writes nothing', function (): void {
    $project = Scratch::directory();

    expect(HookFrameworkFiles::made($project, HookFramework::GrumPhp, GatePin::unknown(), Output::Printed))
        ->toBe(sprintf("grumphp.yml:\n\n%s", hookFrameworkRecipe()->whole(HookFramework::GrumPhp)))
        ->and(is_file(sprintf('%s/grumphp.yml', $project)))->toBeFalse();
});

it('calls the gate where Composer links the project\'s commands', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"config": {"bin-dir": "bin"}}');

    $made = HookFrameworkFiles::made($project, HookFramework::CaptainHook, GatePin::unknown(), Output::Written);

    expect($made)->toBe("Wrote captainhook.json.\nThen install its hooks: bin/captainhook install")
        ->and((string) file_get_contents(sprintf('%s/captainhook.json', $project)))
        ->toContain('"action": "bin/mutation-gate pre-push --stdin={$STDIN}"');
});

it('says why a config it cannot read is not set up', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'grumphp.yaml/inside', '');

    $made = HookFrameworkFiles::made($project, HookFramework::GrumPhp, GatePin::unknown(), Output::Written);

    expect($made instanceof CannotJudge ? $made->why() : $made)
        ->toBe(sprintf('%s/grumphp.yaml could not be read.', $project));
});

it('says why a config it cannot write is not set up', function (): void {
    $file = Scratch::directory();
    Scratch::write($file, 'project', '');
    $made = HookFrameworkFiles::made(sprintf('%s/project', $file), HookFramework::PreCommit, GatePin::unknown(), Output::Written);

    expect($made instanceof CannotJudge ? $made->why() : $made)->toEndWith('.pre-commit-config.yaml could not be written.');
});
