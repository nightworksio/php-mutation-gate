<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\ChildMemory;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use Symfony\Component\Process\Process;

// The child holds random bytes, which a memory compressor cannot shrink as
// it can a run of one byte, so the pages stay resident under memory
// pressure. It holds twice the margin the peak is asked to clear above this
// process's own, so a peak short of everything it held still clears it.
it('counts the memory of a process it waited for, rather than its own', function (): void {
    $margin = 64 * 1024 * 1024;
    $usage = getrusage();
    $own = ChildMemory::bytes(is_array($usage) && is_int($usage['ru_maxrss']) ? $usage['ru_maxrss'] : 0, PHP_OS_FAMILY);
    $block = 1024 * 1024;
    $blocks = intdiv($own + 2 * $margin, $block) + 1;
    $holding = sprintf('$held = str_repeat(random_bytes(%d), %d);', $block, $blocks);
    new Process([PHP_BINARY, '-d', 'memory_limit=-1', '-r', $holding])->mustRun();
    $peak = new ChildMemory()->peak();
    $cleared = MemoryCap::of($own + $margin, MemoryUnit::Bytes);

    expect($peak)->toBeInstanceOf(MemoryCap::class)
        ->and($peak instanceof MemoryCap && $cleared->isExceededBy($peak))->toBeTrue();
});

it('reads ru_maxrss in bytes on macOS, and in kilobytes elsewhere', function (): void {
    expect(ChildMemory::bytes(2048, 'Darwin'))->toBe(2048)
        ->and(ChildMemory::bytes(2048, 'Linux'))->toBe(2048 * 1024)
        ->and(ChildMemory::bytes(2048, 'BSD'))->toBe(2048 * 1024);
});
