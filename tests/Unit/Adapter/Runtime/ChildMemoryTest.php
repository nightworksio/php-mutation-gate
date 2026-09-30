<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\ChildMemory;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use Symfony\Component\Process\Process;

it('counts the memory of a process it waited for', function (): void {
    new Process([PHP_BINARY, '-d', 'memory_limit=-1', '-r', '$held = str_repeat("x", 96 * 1024 * 1024);'])->mustRun();
    $peak = ChildMemory::peak();

    expect($peak)->toBeInstanceOf(MemoryCap::class)
        ->and($peak instanceof MemoryCap && MemoryCap::of(96, MemoryUnit::Megabytes)->isExceededBy($peak))->toBeTrue();
});

it('reads ru_maxrss in bytes on macOS, and in kilobytes elsewhere', function (): void {
    expect(ChildMemory::bytes(2048, 'Darwin'))->toBe(2048)
        ->and(ChildMemory::bytes(2048, 'Linux'))->toBe(2048 * 1024)
        ->and(ChildMemory::bytes(2048, 'BSD'))->toBe(2048 * 1024);
});
