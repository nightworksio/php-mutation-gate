<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerMemory;

it('needs PHP\'s default for the rest of a run, and twenty-four bytes for each byte of the largest ledger', function (): void {
    expect(LedgerMemory::standard()->bytes())->toBe(134_217_728 + 24 * 38_000_000)
        ->and(LedgerMemory::within(LedgerLimits::of(1, 1_000, 60.0))->bytes())->toBe(134_217_728 + 24_000);
});

it('admits a memory_limit of at least what a run may need, or none at all', function (): void {
    $memory = LedgerMemory::within(LedgerLimits::of(1, 1_000, 60.0));

    expect($memory->admits(134_241_728))->toBeTrue()
        ->and($memory->admits(134_241_727))->toBeFalse()
        ->and($memory->admits(-1))->toBeTrue()
        ->and($memory->admits(0))->toBeFalse();
});
