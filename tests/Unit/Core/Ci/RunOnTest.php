<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Unnamed;
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

it('is a ref already read as a scope, a pull request where the scope says so', function (): void {
    expect(RunOn::at(Scope::pullRequest(12), Scope::branch('main'))->isPullRequest())->toBeTrue()
        ->and(RunOn::at(Scope::branch('feature'), Scope::branch('main'))->isPullRequest())->toBeFalse()
        ->and(RunOn::at(Scope::branch('feature'), Scope::branch('main'))->scope())->toEqual(Scope::branch('feature'));
});

it('is a detached HEAD, with no scope and never a pull request', function (): void {
    $run = RunOn::detached(Scope::branch('main'));

    expect($run->scope())->toEqual(Detached::head())
        ->and($run->isPullRequest())->toBeFalse()
        ->and($run->defaultBranch())->toEqual(Scope::branch('main'));
});

it('takes the default branch it is told, in place of the one the CI named', function (): void {
    $run = RunOn::at(Scope::pullRequest(12), CannotTell::because('The CI does not say.'))->withDefaultBranch(Scope::branch('trunk'));

    expect($run->defaultBranch())->toEqual(Scope::branch('trunk'))
        ->and($run->scope())->toEqual(Scope::pullRequest(12));
});

it('names no commit until the CI names one', function (): void {
    $run = RunOn::at(Scope::branch('main'), Scope::branch('main'));

    expect($run->commit())->toEqual(Unnamed::commit())
        ->and($run->withCommit(Revision::ref('5eeca8f'))->commit())->toEqual(Revision::ref('5eeca8f'))
        ->and($run->withCommit(Revision::ref('5eeca8f'))->withDefaultBranch(Scope::branch('main'))->commit())
        ->toEqual(Revision::ref('5eeca8f'));
});

it('keeps the default branch\'s scope only where the checkout is the commit the CI names', function (
    Scope $scope,
    string $named,
    Scope|Detached $kept,
): void {
    $run = RunOn::at($scope, Scope::branch('main'));
    $run = $named === '' ? $run : $run->withCommit(Revision::ref($named));
    $checked = $run->atCheckout(Revision::ref('5eeca8f'));

    expect($checked->scope())->toEqual($kept)
        ->and($checked->defaultBranch())->toEqual(Scope::branch('main'))
        ->and($checked->commit())->toEqual(Unnamed::commit());
})->with([
    'the default branch at the commit named' => [Scope::branch('main'), '5eeca8f', Scope::branch('main')],
    'the default branch at another commit' => [Scope::branch('main'), '206b4e0', Detached::head()],
    'the default branch with no commit named' => [Scope::branch('main'), '', Scope::branch('main')],
    'another branch at another commit' => [Scope::branch('feature'), '206b4e0', Scope::branch('feature')],
    'a pull request at another commit' => [Scope::pullRequest(12), '206b4e0', Scope::pullRequest(12)],
]);

it('keeps a scope where the default branch is not known', function (): void {
    $run = RunOn::at(Scope::branch('main'), CannotTell::because('Unknown.'))->withCommit(Revision::ref('206b4e0'));

    expect($run->atCheckout(Revision::ref('5eeca8f'))->scope())->toEqual(Scope::branch('main'));
});
