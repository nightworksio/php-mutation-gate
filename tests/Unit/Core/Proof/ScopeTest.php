<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Proof\Scope;

it('is the ref whose ledger it is', function (): void {
    expect(Scope::of('refs/pull/12')->ref())->toBe('refs/pull/12');
});

it('is a branch\'s ref or a pull request\'s', function (): void {
    expect(Scope::branch('feat/x')->ref())->toBe('refs/heads/feat/x')
        ->and(Scope::pullRequest(12)->ref())->toBe('refs/pull/12');
});

it('reads a ref that is a branch\'s or a pull request\'s', function (string $ref): void {
    expect(Scope::parse($ref))->toEqual(Scope::of($ref));
})->with(['refs/heads/main', 'refs/heads/feat/x', 'refs/heads/.hidden', 'refs/heads/a..b', 'refs/pull/12']);

it('refuses a ref that is neither, or would lead out of its store', function (string $ref): void {
    expect(Scope::parse($ref))->toEqual(CannotJudge::because(sprintf(
        '"%s" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
        $ref,
    )));
})->with([
    'refs/heads/../x',
    'refs/heads/a/../b',
    'refs/heads/a/..',
    'refs/heads/.',
    'refs/heads/a//b',
    'refs/heads/a/',
    'refs/heads/',
    'refs/heads/a b',
    'refs/pull/0',
    'refs/pull/1/merge',
    'refs/tags/v1',
    "refs/heads/main\n",
]);

it('is the same scope as another of the same ref', function (): void {
    expect(Scope::branch('main')->equals(Scope::of('refs/heads/main')))->toBeTrue()
        ->and(Scope::branch('main')->equals(Scope::pullRequest(12)))->toBeFalse();
});
