<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Proof\LedgerObject;
use NightWorksIO\MutationGate\Core\Proof\Scope;

it('keeps a scope\'s ledger under the prefix, whose empty segments count for nothing', function (
    string $prefix,
    Scope $scope,
    string $key,
): void {
    expect(LedgerObject::under($prefix)->of($scope))->toBe($key);
})->with([
    'a branch' => ['mutation-gate', Scope::branch('main'), 'mutation-gate/refs/heads/main/ledger.json.gz'],
    'a pull request' => ['mutation-gate', Scope::pullRequest(12), 'mutation-gate/refs/pull/12/ledger.json.gz'],
    'a prefix of several segments' => ['/team//mutation-gate/', Scope::branch('main'), 'team/mutation-gate/refs/heads/main/ledger.json.gz'],
    'no prefix' => ['', Scope::branch('release/2.x'), 'refs/heads/release/2.x/ledger.json.gz'],
]);

it('keeps no ledger for a ref that is no scope', function (): void {
    expect(LedgerObject::under('mutation-gate')->of(Scope::of('refs/tags/v1')))->toEqual(CannotJudge::because(
        '"refs/tags/v1" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
    ));
});
