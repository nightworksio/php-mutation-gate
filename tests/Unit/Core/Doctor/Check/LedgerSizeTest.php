<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\LedgerSize;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedger;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedgers;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$ledger = static fn(string $scope, int $bytes): KeptLedger => KeptLedger::of(Path::of(sprintf('.mutation-gate/ledger/%s/ledger.json.gz', $scope)), $bytes);

it('finds each ledger over 25 MB compressed, which slows every run of its scope', function () use ($ledger): void {
    $observed = Observations::none()->withLedgers(KeptLedgers::of(
        $ledger('refs/heads/main', 31_240_000),
        $ledger('refs/heads/small', 25_000_000),
        $ledger('refs/pull/7', 25_000_001),
    ));

    expect(LedgerSize::in($observed))->toEqual(Findings::of(Finding::of(
        Slug::LedgerSlowsRuns,
        Severity::Slow,
        '.mutation-gate/ledger/refs/heads/main/ledger.json.gz is 31.2 MB; .mutation-gate/ledger/refs/pull/7/ledger.json.gz is 25.0 MB, compressed.',
        'Every run restores, decompresses and writes back its scope\'s ledger, so its size is time each run spends.',
        'Delete the ledger of a scope that no longer runs; the next run of a live scope starts it afresh.',
    )));
});

it('finds nothing where every ledger is small enough, or none was read', function () use ($ledger): void {
    expect(LedgerSize::in(Observations::none()->withLedgers(KeptLedgers::of($ledger('refs/heads/main', 1_000)))))->toEqual(Findings::none())
        ->and(LedgerSize::in(Observations::none()))->toEqual(Findings::none());
});
