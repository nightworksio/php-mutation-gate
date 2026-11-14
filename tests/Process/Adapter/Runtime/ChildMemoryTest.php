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

it('counts the memory of a process it waited for, rather than its own', function (): void {
    $held = 128 * 1024 * 1024;
    $block = 1024 * 1024;
    $holding = sprintf('$held = str_repeat(random_bytes(%d), %d);', $block, intdiv($held, $block));
    new Process([PHP_BINARY, '-d', 'memory_limit=-1', '-r', $holding])->mustRun();
    $before = MaxRss::held(childMemoryOwnUsage());
    $peak = new ChildMemory()->peak();
    $after = MaxRss::held(childMemoryOwnUsage());

    expect($peak)->toBeInstanceOf(MemoryCap::class)
        ->and($peak instanceof MemoryCap && MemoryCap::of(intdiv($held, 4), MemoryUnit::Bytes)->isExceededBy($peak))->toBeTrue()
        ->and($peak instanceof MemoryCap && $peak->bytes() >= MaxRss::bytes($before, PHP_OS_FAMILY) && $peak->bytes() <= MaxRss::bytes($after, PHP_OS_FAMILY))->toBeFalse();
})->group(...$holds);

it('counts none in a process that has waited for none', function (): void {
    $peak = new Process([PHP_BINARY, '-r', sprintf(
        'require %s; echo get_class(new NightWorksIO\MutationGate\Adapter\Runtime\ChildMemory()->peak());',
        var_export(sprintf('%s/vendor/autoload.php', dirname(__DIR__, 4)), return: true),
    )]);

    expect($peak->mustRun()->getOutput())->toBe(NotGiven::class);
})->group(...$holds);

/**
 * @return array<string, int>
 */
function childMemoryOwnUsage(): array
{
    $usage = getrusage();
    $counted = [];

    foreach (is_array($usage) ? $usage : [] as $name => $count) {
        $counted = is_string($name) && is_int($count) ? [...$counted, $name => $count] : $counted;
    }

    return $counted;
}
