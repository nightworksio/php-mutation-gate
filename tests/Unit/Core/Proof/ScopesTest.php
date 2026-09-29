<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Scopes;

$refs = static fn(Scopes $scopes): array => array_map(
    static fn(Scope $scope): string => $scope->ref(),
    iterator_to_array($scopes, preserve_keys: true),
);

it('holds scopes in the order they came, each once', function () use ($refs): void {
    $scopes = Scopes::of(Scope::pullRequest(12), Scope::branch('main'), Scope::pullRequest(12));

    expect($refs($scopes))->toBe(['refs/pull/12', 'refs/heads/main'])
        ->and($scopes)->toHaveCount(2);
});

it('says whether it holds a scope', function (): void {
    $scopes = Scopes::of(Scope::branch('main'));

    expect($scopes->has(Scope::of('refs/heads/main')))->toBeTrue()
        ->and($scopes->has(Scope::pullRequest(12)))->toBeFalse()
        ->and(Scopes::of())->toHaveCount(0);
});
