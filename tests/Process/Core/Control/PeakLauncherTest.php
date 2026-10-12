<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

it('runs a command with its own output and exit code, and writes the most memory it held', function (): void {
    $directory = Scratch::directory();
    $launcher = PeakLauncher::in($directory);
    $peak = sprintf('%s/peak', $directory);
    file_put_contents($launcher, PeakLauncher::SCRIPT);
    $process = new Process([PHP_BINARY, $launcher, $peak, PHP_BINARY, '-r', '$held = str_repeat("x", 64 * 1024 * 1024); echo "held"; exit(3);']);
    $process->run();
    $measured = PeakLauncher::peakIn((string) file_get_contents($peak), PHP_OS_FAMILY);

    expect($process->getOutput())->toBe('held')
        ->and($process->getExitCode())->toBe(3)
        ->and($measured instanceof MemoryCap ? $measured->bytes() : 0)->toBeGreaterThan(64 * 1024 * 1024);
});
