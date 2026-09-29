<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Proof\Scope;

it('is a branch, scoped by its ref, with the default branch the CI names', function (): void {
    $run = RunOn::branch('feature/money', Scope::branch('main'));

    expect($run)->toBeInstanceOf(RunOn::class)
        ->and($run instanceof RunOn ? $run->scope() : null)->toEqual(Scope::of('refs/heads/feature/money'))
        ->and($run instanceof RunOn && $run->isPullRequest())->toBeFalse()
        ->and($run instanceof RunOn ? $run->defaultBranch() : null)->toEqual(Scope::of('refs/heads/main'));
});

it('is a pull request, scoped by its number, where the CI may not name the default branch', function (): void {
    $unknown = CannotTell::because('The CI does not say.');
    $run = RunOn::pullRequest('12', $unknown);

    expect($run instanceof RunOn ? $run->scope() : null)->toEqual(Scope::of('refs/pull/12'))
        ->and($run instanceof RunOn && $run->isPullRequest())->toBeTrue()
        ->and($run instanceof RunOn ? $run->defaultBranch() : null)->toBe($unknown);
});

it('cannot tell the scope of a ref that is not a branch or a pull request', function (): void {
    expect(RunOn::branch('a b', Scope::branch('main')))->toEqual(CannotTell::because(
        '"refs/heads/a b" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
    ))->and(RunOn::pullRequest('twelve', Scope::branch('main')))->toEqual(CannotTell::because(
        '"refs/pull/twelve" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
    ));
});

it('reads a default branch the CI names', function (): void {
    expect(RunOn::branchNamed('main'))->toEqual(Scope::of('refs/heads/main'))
        ->and(RunOn::branchNamed(''))->toEqual(CannotTell::because(
            '"refs/heads/" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
        ));
});
