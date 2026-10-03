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

it('names a scope\'s ledger as a URL\'s path, each segment of the prefix and the ref percent-encoded', function (
    string $prefix,
    string $branch,
    string $path,
): void {
    expect(LedgerObject::under($prefix)->path(Scope::branch($branch)))->toBe($path);
})->with([
    'plain segments' => ['mutation-gate', 'release/2.x', 'mutation-gate/refs/heads/release/2.x/ledger.json.gz'],
    'a prefix to encode' => ['team ledgers/gate', 'main', 'team%20ledgers/gate/refs/heads/main/ledger.json.gz'],
    'encoded dots' => ['', '%2e%2e/secret', 'refs/heads/%252e%252e/secret/ledger.json.gz'],
    'a fragment\'s mark' => ['', 'main#', 'refs/heads/main%23/ledger.json.gz'],
    'a name beyond ASCII' => ['', 'zürich', 'refs/heads/z%C3%BCrich/ledger.json.gz'],
]);

it('names no path for a ref that is no scope', function (): void {
    expect(LedgerObject::under('mutation-gate')->path(Scope::of('refs/tags/v1')))->toBeInstanceOf(CannotJudge::class);
});

it('percent-encodes each segment of a key as a URL\'s path, keeping its slashes', function (): void {
    expect(LedgerObject::encoded('gate/refs/heads/feature/añadir #1/ledger.json.gz'))
        ->toBe('gate/refs/heads/feature/a%C3%B1adir%20%231/ledger.json.gz');
});
