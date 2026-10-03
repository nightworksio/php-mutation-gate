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

it('finds each ledger past the compressed limit a run reads to, which no run reads', function () use ($ledger): void {
    $observed = Observations::none()->withLedgers(KeptLedgers::of(
        $ledger('refs/heads/main', 31_240_000),
        $ledger('refs/heads/small', 11_000_000),
        $ledger('refs/pull/7', 11_000_001),
    ));

    expect(LedgerSize::in($observed))->toEqual(Findings::of(Finding::of(
        Slug::LedgerTooLarge,
        Severity::Slow,
        '.mutation-gate/ledger/refs/heads/main/ledger.json.gz is 31.2 MB; .mutation-gate/ledger/refs/pull/7/ledger.json.gz is 11.0 MB, compressed.',
        'No run reads a ledger past 11.0 MB compressed, twice one at the retention cap: this one was never trimmed.',
        'Delete it, or the file that is not a ledger; the next run of its scope writes its ledger afresh.',
    )));
});

it('says each size in megabytes to one decimal, rounded to the nearer', function () use ($ledger): void {
    $finding = [...LedgerSize::in(Observations::none()->withLedgers(KeptLedgers::of(
        $ledger('refs/heads/main', 11_250_001),
        $ledger('refs/pull/7', 11_249_999),
    )))][0];

    expect($finding->found())->toBe(
        '.mutation-gate/ledger/refs/heads/main/ledger.json.gz is 11.3 MB; .mutation-gate/ledger/refs/pull/7/ledger.json.gz is 11.2 MB, compressed.',
    );
});

it('finds nothing where every ledger is small enough, or none was read', function () use ($ledger): void {
    expect(LedgerSize::in(Observations::none()->withLedgers(KeptLedgers::of($ledger('refs/heads/main', 1_000)))))->toEqual(Findings::none())
        ->and(LedgerSize::in(Observations::none()))->toEqual(Findings::none());
});
