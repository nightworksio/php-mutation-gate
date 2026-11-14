<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\SystemGauge;
use NightWorksIO\MutationGate\Core\Runner\Remaining;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the memory a process can still take and the swap used as Linux says them, in bytes', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'meminfo', <<<'MEMINFO'
        MemTotal:       16323584 kB
        MemFree:        13125632 kB
        MemAvailable:   14831616 kB
        SwapTotal:       3145724 kB
        SwapFree:        2097148 kB

        MEMINFO);

    $remaining = new SystemGauge(sprintf('%s/meminfo', $directory), '/no/such/directory')->remaining();

    expect($remaining)->toEqual(Remaining::unknown()->withMemory(14_831_616 * 1024, 16_323_584 * 1024)->withSwap(1_048_576 * 1024, 3_145_724 * 1024));
});

it('reads the disk free under the directory, and no memory where the system says none', function (): void {
    $remaining = new SystemGauge('/no/such/meminfo', Scratch::directory())->remaining();

    expect($remaining->text())->toMatch('/^disk \d+\.\d GB free$/');
});

it('reads no memory a line leaves out, nor swap', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'meminfo', "MemTotal:       16323584 kB\nSwapFree:        2097148 kB\n");

    expect(new SystemGauge(sprintf('%s/meminfo', $directory), '/no/such/directory')->remaining())->toEqual(Remaining::unknown());
});
