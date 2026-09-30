<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerMemory;

it('needs PHP\'s default for the rest of a run, and thirty-eight bytes for each byte of the largest ledger', function (): void {
    expect(LedgerMemory::standard()->bytes())->toBe(134_217_728 + 38 * 38_000_000)
        ->and(LedgerMemory::within(LedgerLimits::of(1, 1_000, 60.0))->bytes())->toBe(134_217_728 + 38_000);
});

it('admits a memory_limit of at least what a run may need, or none at all', function (): void {
    $memory = LedgerMemory::within(LedgerLimits::of(1, 1_000, 60.0));

    expect($memory->admits(134_255_728))->toBeTrue()
        ->and($memory->admits(134_255_727))->toBeFalse()
        ->and($memory->admits(-1))->toBeTrue()
        ->and($memory->admits(0))->toBeFalse();
});

it('counts what a run may need in PHP\'s M, rounding up to a whole one', function (): void {
    expect(LedgerMemory::standard()->mebibytes())->toBe(1_506)
        ->and(LedgerMemory::within(LedgerLimits::of(1, 0, 60.0))->mebibytes())->toBe(128)
        ->and(LedgerMemory::within(LedgerLimits::of(1, 1, 60.0))->mebibytes())->toBe(129);
});
