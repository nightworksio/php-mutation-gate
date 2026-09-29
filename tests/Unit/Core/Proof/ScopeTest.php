<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\Scope;

it('is the ref whose ledger it is', function (): void {
    expect(Scope::of('refs/pull/12')->ref())->toBe('refs/pull/12');
});
