<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\MemoryLimit;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Proof\LedgerMemory;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$ran = static fn(int $limit): Observations => Observations::none()
    ->in(DoctorRun::of(new DateTimeImmutable(Configs::NOW), $limit));

it('finds a memory_limit under what a run over the largest ledgers may need', function () use ($ran): void {
    expect(MemoryLimit::in($ran(134_217_728)))->toEqual(Findings::of(Finding::of(
        Slug::MemoryLimitLow,
        Severity::WillFail,
        'The gate\'s own PHP has a memory_limit of 128M, and could not be given more.',
        'A run over ledgers as large as a run reads can take 1324M, and PHP stops it past that.',
        'Set memory_limit to 1324M or more, or to -1, for the PHP that runs the gate.',
    )));
});

it('finds nothing where the limit is enough, or none, or doctor\'s own run is not known', function () use (
    $ran,
): void {
    expect(MemoryLimit::in($ran(LedgerMemory::standard()->bytes())))->toEqual(Findings::none())
        ->and(MemoryLimit::in($ran(-1)))->toEqual(Findings::none())
        ->and(MemoryLimit::in(Observations::none()))->toEqual(Findings::none());
});
