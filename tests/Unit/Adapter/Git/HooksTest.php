<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Command;
use NightWorksIO\MutationGate\Adapter\Git\Hooks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\Hook\HookCall;
use NightWorksIO\MutationGate\Core\Hook\Removed;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The hooks of the repository git finds from a directory; a failure fails the test. */
function hooksFrom(string $directory): Hooks
{
    $hooks = Hooks::of(Command::in($directory));

    return $hooks instanceof Hooks ? $hooks : throw new LogicException('git found no repository');
}

it('keeps the hooks where git runs them from', function (): void {
    $repository = Repository::empty();

    expect(hooksFrom($repository->root)->where(Hook::PrePush))
        ->toBe(sprintf('%s/.git/hooks/pre-push', realpath($repository->root)));
});

it('keeps them where core.hooksPath says, when it says', function (): void {
    $repository = Repository::empty();
    $repository->git('config', 'core.hooksPath', '.githooks');

    expect(hooksFrom($repository->root)->where(Hook::PreCommit))
        ->toBe(sprintf('%s/.githooks/pre-commit', realpath($repository->root)));
});

it('keeps a linked worktree\'s hooks with the repository\'s, where git runs them from', function (): void {
    $repository = Repository::empty()->commit('The base.');
    $linked = sprintf('%s/linked', Scratch::directory());
    $repository->git('worktree', 'add', '--quiet', $linked);

    expect(hooksFrom($linked)->where(Hook::PrePush))
        ->toBe(sprintf('%s/.git/hooks/pre-push', realpath($repository->root)));
});

it('runs its hooks from the repository, or from a directory other repositories share', function (): void {
    $repository = Repository::empty()->commit('The base.');
    $linked = sprintf('%s/linked', Scratch::directory());
    $repository->git('worktree', 'add', '--quiet', $linked);
    $inside = hooksFrom($repository->root)->isShared();
    $fromLinked = hooksFrom($linked)->isShared();
    $repository->git('config', 'core.hooksPath', '.githooks');
    $tracked = hooksFrom($repository->root)->isShared();
    $repository->git('config', 'core.hooksPath', Scratch::directory());

    expect([$inside, $fromLinked, $tracked, hooksFrom($repository->root)->isShared()])->toBe([false, false, false, true]);
});

it('cannot tell where the repository is in a git directory without a working tree', function (): void {
    $repository = Repository::empty();

    expect(Hooks::of(Command::in(sprintf('%s/.git', $repository->root))))->toBeInstanceOf(CannotTell::class);
});

it('calls the gate from the top of the working tree into the project', function (): void {
    $repository = Repository::empty()->write('packages/app/composer.json', '{}');
    $binary = Path::of('vendor/bin/mutation-gate');

    expect(hooksFrom($repository->root)->call($binary))->toEqual(HookCall::of(Path::root(), $binary))
        ->and(hooksFrom(sprintf('%s/packages/app', $repository->root))->call($binary))
        ->toEqual(HookCall::of(Path::of('packages/app'), $binary));
});

it('cannot tell where the hooks are outside a repository', function (): void {
    expect(Hooks::of(Command::in(Scratch::directory())))->toBeInstanceOf(CannotTell::class);
});

it('writes a hook everyone can read and run and its owner can write, creating the directory, and reads it back', function (): void {
    $repository = Repository::empty();
    $repository->git('config', 'core.hooksPath', 'hooks/not-yet');
    $hooks = hooksFrom($repository->root);

    expect($hooks->read(Hook::PrePush))->toEqual(Missing::at(Path::of('pre-push')))
        ->and($hooks->write(Hook::PrePush, Contents::of("#!/bin/sh\nexit 0\n")))->toEqual(Written::to($hooks->where(Hook::PrePush)))
        ->and($hooks->read(Hook::PrePush))->toEqual(Contents::of("#!/bin/sh\nexit 0\n"))
        ->and(fileperms($hooks->where(Hook::PrePush)) & 0o777)->toBe(0o755);
});

it('writes no hook where a directory is in its place, or where its directory is a file', function (): void {
    $repository = Repository::empty();
    mkdir(sprintf('%s/.git/hooks/pre-push', $repository->root));
    $hooks = hooksFrom($repository->root);
    $repository->git('config', 'core.hooksPath', 'a-file');
    Scratch::write($repository->root, 'a-file', '');
    $blocked = hooksFrom($repository->root);

    expect($hooks->write(Hook::PrePush, Contents::of('')))
        ->toEqual(CannotJudge::because(sprintf('%s could not be written.', $hooks->where(Hook::PrePush))))
        ->and($hooks->read(Hook::PrePush))->toEqual(Missing::at(Path::of('pre-push')))
        ->and($blocked->write(Hook::PreCommit, Contents::of('')))
        ->toEqual(CannotJudge::because(sprintf('%s could not be written.', $blocked->where(Hook::PreCommit))));
});

it('writes no hook over one it may not write', function (): void {
    $hooks = hooksFrom(Repository::empty()->root);
    $hooks->write(Hook::PrePush, Contents::of("kept\n"));
    chmod($hooks->where(Hook::PrePush), 0o444);

    expect($hooks->write(Hook::PrePush, Contents::of('')))
        ->toEqual(CannotJudge::because(sprintf('%s could not be written.', $hooks->where(Hook::PrePush))))
        ->and($hooks->read(Hook::PrePush))->toEqual(Contents::of("kept\n"));
});

it('writes over a hook it may write in a directory it may not', function (): void {
    $hooks = hooksFrom(Repository::empty()->root);
    $hooks->write(Hook::PrePush, Contents::of("old\n"));
    $directory = dirname($hooks->where(Hook::PrePush));
    chmod($directory, 0o555);

    try {
        $written = $hooks->write(Hook::PrePush, Contents::of("new\n"));
    } finally {
        chmod($directory, 0o755);
    }

    expect($written)->toEqual(Written::to($hooks->where(Hook::PrePush)))
        ->and($hooks->read(Hook::PrePush))->toEqual(Contents::of("new\n"));
});

it('removes a hook, and says so where there is none to remove', function (): void {
    $hooks = hooksFrom(Repository::empty()->root);
    $hooks->write(Hook::PreCommit, Contents::of(''));

    expect($hooks->remove(Hook::PreCommit))->toEqual(Removed::from($hooks->where(Hook::PreCommit)))
        ->and(file_exists($hooks->where(Hook::PreCommit)))->toBeFalse()
        ->and($hooks->remove(Hook::PreCommit))
        ->toEqual(CannotJudge::because(sprintf('%s could not be removed.', $hooks->where(Hook::PreCommit))));
});
