<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;

it('says why a run writes no ledger', function (): void {
    expect(ReadsOnly::because('proofs.write is never.')->why())->toBe('proofs.write is never.');
});
