<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Proof\Passed;

it('is a passing commit, the check it reported under, and how many of its own scope\'s proofs it used', function (): void {
    $passed = Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 2);

    expect($passed->commit())->toEqual(Revision::ref('206b4e0'))
        ->and($passed->check())->toBe('mutation / verdict')
        ->and($passed->ownScopeProofs())->toBe(2)
        ->and($passed->usedOwnScope())->toBeTrue()
        ->and(Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 0)->usedOwnScope())->toBeFalse()
        ->and(Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 1)->usedOwnScope())->toBeTrue();
});
