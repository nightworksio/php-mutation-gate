<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\LiveProcesses;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A `/proc` under a scratch directory: these processes' stat files, its own, and how long the machine is up. */
function liveProcessesRoot(string ...$stats): string
{
    $root = Scratch::directory();
    Scratch::write($root, 'uptime', "1500.50 6000.00\n");
    Scratch::write($root, 'self/stat', '9 (php) R 1 9 9 0 -1 0 0 0 0 0 0 0 0 0 20 0 1 0 0 0 0');

    foreach ($stats as $stat) {
        Scratch::write($root, sprintf('%d/stat', (int) $stat), $stat);
    }

    return $root;
}

function liveProcessesStat(int $pid, string $name, int $parent, int $started, int $pages): string
{
    return sprintf('%d (%s) S %d %d %d 0 -1 4194560 120 0 0 0 1 2 0 0 20 0 1 0 %d 8638464 %d 0 0', $pid, $name, $parent, $pid, $pid, $started, $pages);
}

it('reads each process of /proc from its stat file, with the machine\'s own clock ticks and page size', function (): void {
    $root = liveProcessesRoot(liveProcessesStat(100, 'a) S 1 (b', 1, 50_000, 3), liveProcessesStat(101, 'sleep', 100, 100_000, 1));
    $ticks = posix_sysconf(POSIX_SC_CLK_TCK);
    $kibibytes = intdiv(posix_sysconf(POSIX_SC_PAGESIZE), 1_024);

    expect(LiveProcesses::read($root)->uses())->toBe([
        ['pid' => 100, 'parent' => 1, 'rss' => 3 * $kibibytes, 'age' => (int) (1500.5 - 50_000 / $ticks), 'name' => 'a) S 1 (b'],
        ['pid' => 101, 'parent' => 100, 'rss' => $kibibytes, 'age' => (int) (1500.5 - 100_000 / $ticks), 'name' => 'sleep'],
    ]);
})->skip(fn(): bool => ! function_exists('posix_sysconf'), 'PHP cannot ask how the system counts');

it('leaves out a process that ended between the listing of /proc and the read of its state, without a warning', function (): void {
    $root = liveProcessesRoot(liveProcessesStat(100, 'sh', 1, 0, 1));
    mkdir(sprintf('%s/101', $root));
    Scratch::write($root, 'net/stat', '1 (not a process) S 1 1 1 0 -1 0 0 0 0 0 0 0 0 0 20 0 1 0 0 0 0');

    expect(LiveProcesses::read($root)->parents())->toBe([100 => 1]);
})->skip(fn(): bool => ! function_exists('posix_sysconf'), 'PHP cannot ask how the system counts');

it('lists nothing from a /proc that is gone, without a warning', function (): void {
    expect(LiveProcesses::read(sprintf('%s/gone', Scratch::directory()))->uses())->toBe([]);
})->skip(fn(): bool => ! function_exists('posix_sysconf'), 'PHP cannot ask how the system counts');

it('reads /proc where the system has one', function (): void {
    expect(new LiveProcesses(liveProcessesRoot(liveProcessesStat(100, 'sh', 1, 0, 1)))->listed()->parents())->toBe([100 => 1]);
})->skip(fn(): bool => ! function_exists('posix_sysconf'), 'PHP cannot ask how the system counts');

it('lists the processes by ps where the system has no /proc', function (): void {
    $listing = new LiveProcesses(Scratch::directory())->listed();

    expect($listing->parents())->toHaveKey((int) getmypid())
        ->and($listing->parents()[(int) getmypid()])->toBe(posix_getppid())
        ->and($listing->groupOf((int) getmypid()))->toBe(posix_getpgrp());
});
