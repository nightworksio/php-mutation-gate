<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

$holds = [
    'holds:src/Adapter/Git/Command.php',
    'holds:src/Adapter/Git/Hooks.php',
    'holds:src/Cli/Command/HookCommand.php',
    'holds:src/Cli/ComposerVendor.php',
    'holds:src/Cli/Console.php',
    'holds:src/Core/Hook/Hook.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

/** What a hook of a repository holds, or nothing where there is none. */
function hookOf(Repository $repository, string $hook): string
{
    $file = sprintf('%s/.git/hooks/%s', $repository->root, $hook);

    return is_file($file) ? (string) file_get_contents($file) : '';
}

/** The hook's file, as the command names it. */
function hookFile(Repository $repository, string $hook): string
{
    return sprintf('%s/.git/hooks/%s', realpath($repository->root), $hook);
}

const PRE_PUSH = <<<'SH'
    #!/bin/sh
    # Written by `mutation-gate hook install`, and removed by `mutation-gate hook uninstall`.
    exec vendor/bin/mutation-gate pre-push "$@"

    SH;

it('installs the pre-push hook alone, calling the gate by its Composer binary', function (): void {
    $repository = Repository::empty();

    $printed = Commands::run($repository->root, 'hook', ['action' => 'install']);

    expect([$printed->code, $printed->output, $printed->errors])->toBe([0, sprintf("Wrote %s.\n", hookFile($repository, 'pre-push')), ''])
        ->and(hookOf($repository, 'pre-push'))->toBe(PRE_PUSH)
        ->and(hookOf($repository, 'pre-commit'))->toBe('');
})->group(...$holds);

it('installs the pre-commit hook too when asked', function (): void {
    $repository = Repository::empty();

    $printed = Commands::run($repository->root, 'hook', ['action' => 'install', '--pre-commit' => true]);

    expect($printed->output)->toBe(sprintf("Wrote %s.\nWrote %s.\n", hookFile($repository, 'pre-push'), hookFile($repository, 'pre-commit')))
        ->and(hookOf($repository, 'pre-commit'))->toContain('exec vendor/bin/mutation-gate pre-commit "$@"');
})->group(...$holds);

it('writes its own hook again, and leaves somebody else\'s as it is with the line that calls the gate', function (): void {
    $repository = Repository::empty()->write('.git/hooks/pre-push', "#!/bin/sh\nnpm test\n");
    Scratch::write($repository->root, '.git/hooks/pre-commit', "#!/bin/sh\n# Written by `mutation-gate hook install`, and removed by `mutation-gate hook uninstall`.\nold\n");

    $printed = Commands::run($repository->root, 'hook', ['action' => 'install', '--pre-commit' => true]);

    expect([$printed->code, $printed->output])->toBe([0, sprintf(
        "%s is a hook the gate did not write, so it is left as it is. To run the gate from it, add: %s\nWrote %s.\n",
        hookFile($repository, 'pre-push'),
        'vendor/bin/mutation-gate pre-push "$@" || exit 1',
        hookFile($repository, 'pre-commit'),
    )])
        ->and(hookOf($repository, 'pre-push'))->toBe("#!/bin/sh\nnpm test\n")
        ->and(hookOf($repository, 'pre-commit'))->toContain('exec vendor/bin/mutation-gate pre-commit "$@"');
})->group(...$holds);

it('writes a hook that enters a project below the top of the working tree and runs the gate there', function (): void {
    $repository = Repository::empty()->write('app/vendor/bin/mutation-gate', "#!/bin/sh\necho \"\$(basename \"\$PWD\") \$*\"\n");
    chmod(sprintf('%s/app/vendor/bin/mutation-gate', $repository->root), 0o755);

    Commands::run(sprintf('%s/app', $repository->root), 'hook', ['action' => 'install']);
    $hook = new Process(['sh', sprintf('%s/.git/hooks/pre-push', $repository->root), 'origin', 'git@example.com:x.git'], $repository->root);
    $hook->mustRun();

    expect($hook->getOutput())->toBe("app pre-push origin git@example.com:x.git\n");
})->group(...$holds);

it('calls the gate where Composer links its commands', function (): void {
    $repository = Repository::empty()->write('composer.json', '{"config": {"bin-dir": "bin"}}');

    Commands::run($repository->root, 'hook', ['action' => 'install']);

    expect(hookOf($repository, 'pre-push'))->toContain("exec bin/mutation-gate pre-push \"\$@\"\n");
})->group(...$holds);

it('writes no hook into a directory other repositories run their hooks from, and prints the line to add', function (): void {
    $repository = Repository::empty();
    $shared = Scratch::directory();
    $repository->git('config', 'core.hooksPath', $shared);

    $printed = Commands::run($repository->root, 'hook', ['action' => 'install', '--pre-commit' => true]);

    expect([$printed->code, $printed->errors])->toBe([0, ''])
        ->and($printed->output)->toBe(sprintf(
            "%1\$s/pre-push is outside this repository, so other repositories run its hooks too; it is left as it is. "
            . "To run the gate from a hook there, add: vendor/bin/mutation-gate pre-push \"\$@\" || exit 1\n"
            . "%1\$s/pre-commit is outside this repository, so other repositories run its hooks too; it is left as it is. "
            . "To run the gate from a hook there, add: vendor/bin/mutation-gate pre-commit \"\$@\" || exit 1\n",
            realpath($shared),
        ))
        ->and(file_exists(sprintf('%s/pre-push', $shared)))->toBeFalse()
        ->and(file_exists(sprintf('%s/pre-commit', $shared)))->toBeFalse();
})->group(...$holds);

it('uninstalls only the hooks it wrote', function (): void {
    $repository = Repository::empty()->write('.git/hooks/pre-commit', "#!/bin/sh\nnpm test\n");
    Commands::run($repository->root, 'hook', ['action' => 'install']);

    $printed = Commands::run($repository->root, 'hook', ['action' => 'uninstall']);

    expect([$printed->code, $printed->output])->toBe([0, sprintf("Removed %s.\n", hookFile($repository, 'pre-push'))])
        ->and(hookOf($repository, 'pre-push'))->toBe('')
        ->and(hookOf($repository, 'pre-commit'))->toBe("#!/bin/sh\nnpm test\n");
})->group(...$holds);

it('says so when there is no hook of its own to uninstall, naming none of anybody else\'s', function (): void {
    $printed = Commands::run(
        Repository::empty()->write('.git/hooks/pre-push', "#!/bin/sh\nnpm test\n")->root,
        'hook',
        ['action' => 'uninstall'],
    );

    expect([$printed->code, $printed->output])->toBe([0, "There is no hook the gate wrote to remove.\n"]);
})->group(...$holds);

it('refuses an action it does not know, and a project outside a repository', function (): void {
    $unknown = Commands::run(Repository::empty()->root, 'hook', ['action' => 'reinstall']);
    $outside = Commands::run(Scratch::directory(), 'hook', ['action' => 'install']);

    expect([$unknown->code, $unknown->errors])->toBe([2, "mutation-gate hook takes install or uninstall, not \"reinstall\".\n"])
        ->and($outside->code)->toBe(2)
        ->and($outside->errors)->toContain('not a git repository');
})->group(...$holds);

it('stops when a hook cannot be written', function (): void {
    $repository = Repository::empty();
    mkdir(sprintf('%s/.git/hooks/pre-push', $repository->root));

    $printed = Commands::run($repository->root, 'hook', ['action' => 'install']);

    expect([$printed->code, $printed->errors])->toBe([2, sprintf("%s could not be written.\n", hookFile($repository, 'pre-push'))]);
})->group(...$holds);

it('stops when a hook of its own cannot be removed', function (): void {
    $repository = Repository::empty();
    Commands::run($repository->root, 'hook', ['action' => 'install']);
    chmod(sprintf('%s/.git/hooks', $repository->root), 0o555);

    try {
        $printed = Commands::run($repository->root, 'hook', ['action' => 'uninstall']);
    } finally {
        chmod(sprintf('%s/.git/hooks', $repository->root), 0o755);
    }

    expect([$printed->code, $printed->errors])->toBe([2, sprintf("%s could not be removed.\n", hookFile($repository, 'pre-push'))])
        ->and(hookOf($repository, 'pre-push'))->toBe(PRE_PUSH);
})->group(...$holds);
