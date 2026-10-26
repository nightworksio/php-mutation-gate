<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\ChildMemory;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MaxRss;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use Symfony\Component\Process\Process;

$holds = [
    'holds:src/Adapter/Runtime/ChildMemory.php',
];

// The child holds random bytes, which a memory compressor cannot shrink as
// it can a run of one byte, so the pages stay resident under memory
// pressure. It holds twice the margin the peak is asked to clear above this
// process's own, so a peak short of everything it held still clears it.
it('counts the memory of a process it waited for, rather than its own', function (): void {
    $margin = 64 * 1024 * 1024;
    $usage = getrusage();
    $own = MaxRss::bytes(is_array($usage) && is_int($usage['ru_maxrss']) ? $usage['ru_maxrss'] : 0, PHP_OS_FAMILY);
    $block = 1024 * 1024;
    $blocks = intdiv($own + 2 * $margin, $block) + 1;
    $holding = sprintf('$held = str_repeat(random_bytes(%d), %d);', $block, $blocks);
    new Process([PHP_BINARY, '-d', 'memory_limit=-1', '-r', $holding])->mustRun();
    $peak = new ChildMemory()->peak();
    $cleared = MemoryCap::of($own + $margin, MemoryUnit::Bytes);

    expect($peak)->toBeInstanceOf(MemoryCap::class)
        ->and($peak instanceof MemoryCap && $cleared->isExceededBy($peak))->toBeTrue();
})->group(...$holds);

it('counts none in a process that has waited for none', function (): void {
    $peak = new Process([PHP_BINARY, '-r', sprintf(
        'require %s; echo get_class(new NightWorksIO\MutationGate\Adapter\Runtime\ChildMemory()->peak());',
        var_export(sprintf('%s/vendor/autoload.php', dirname(__DIR__, 4)), return: true),
    )]);

    expect($peak->mustRun()->getOutput())->toBe(NotGiven::class);
})->group(...$holds);
