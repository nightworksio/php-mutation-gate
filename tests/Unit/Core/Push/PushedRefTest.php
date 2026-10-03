<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Push\PushedRef;

const NO_PUSHED_COMMIT = '0000000000000000000000000000000000000000';

it('pushes a commit from a local ref', function (): void {
    $ref = PushedRef::of('refs/heads/feature', 'a1b2c3', 'd4e5f6');

    expect($ref->local())->toEqual(Revision::ref('a1b2c3'))
        ->and($ref->localRef())->toBe('refs/heads/feature')
        ->and($ref->deletes())->toBeFalse();
});

it('is read since the commit the remote holds, or the default branch for a ref the remote does not hold', function (): void {
    $main = Revision::ref('refs/remotes/origin/main');

    expect(PushedRef::of('refs/heads/feature', 'a1b2c3', 'd4e5f6')->base($main))
        ->toEqual(Revision::ref('d4e5f6'))
        ->and(PushedRef::of('refs/heads/feature', 'a1b2c3', NO_PUSHED_COMMIT)->base($main))
        ->toEqual($main)
        ->and(PushedRef::of('refs/heads/feature', 'a1b2c3', '0000000000000000000000000000000000000000000000000000000000000000')->base($main))
        ->toEqual($main);
});

it('deletes the remote ref where it sends no commit', function (): void {
    expect(PushedRef::of('(delete)', NO_PUSHED_COMMIT, 'd4e5f6')->deletes())->toBeTrue()
        ->and(PushedRef::of('refs/heads/x', '00a0', 'd4e5f6')->deletes())->toBeFalse();
});
