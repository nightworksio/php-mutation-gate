<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Scopes;
use NightWorksIO\MutationGate\Core\Proof\Writing;

it('reads a pull request\'s own ledger and then the default branch\'s', function (): void {
    expect(Access::of(Scope::pullRequest(12), Scope::branch('main'), Writing::Auto)->reads())
        ->toEqual(Scopes::of(Scope::pullRequest(12), Scope::branch('main')));
});

it('reads the default branch\'s ledger once on the default branch', function (): void {
    expect(Access::of(Scope::branch('main'), Scope::branch('main'), Writing::Auto)->reads())
        ->toEqual(Scopes::of(Scope::branch('main')));
});

it('writes only its own scope, so a pull request never writes what the default branch trusts', function (): void {
    expect(Access::of(Scope::pullRequest(12), Scope::branch('main'), Writing::Auto)->writes())->toEqual(Scope::pullRequest(12))
        ->and(Access::of(Scope::branch('main'), Scope::branch('main'), Writing::Auto)->writes())->toEqual(Scope::branch('main'));
});

it('writes nothing where proofs are never written', function (): void {
    expect(Access::of(Scope::pullRequest(12), Scope::branch('main'), Writing::Never)->writes())
        ->toEqual(ReadsOnly::because('proofs.write is never, so the ledger of refs/pull/12 is read and not written.'));
});

it('reads only the default branch\'s ledger, and writes none, for a run with no ref', function (Writing $writing): void {
    $access = Access::of(Detached::head(), Scope::branch('main'), $writing);

    expect($access->reads())->toEqual(Scopes::of(Scope::branch('main')))
        ->and($access->writes())->toEqual(ReadsOnly::because(
            'The run has no ref of its own, so it reads the ledger of refs/heads/main and writes none.',
        ));
})->with([Writing::Auto, Writing::Never]);
