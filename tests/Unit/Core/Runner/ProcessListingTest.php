<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessListing;

/** A process's `stat` file as Linux writes it, with this name, parent, group, start in ticks and pages. */
function processListingStat(int $pid, string $name, int $parent, int $group, int $started, int $pages): string
{
    return sprintf('%d (%s) S %d %d %d 0 -1 4194560 120 0 0 0 1 2 0 0 20 0 1 0 %d 8638464 %d 18446744073709551615 1 1 0 0 0 0 0 0 0 0 0 0 17 3 0 0 0 0 0', $pid, $name, $parent, $group, $group, $started, $pages);
}

it('lists every process ps prints with its parent, group, memory, age and name, skipping a line that names none', function (): void {
    $listing = ProcessListing::printed(<<<'PS'
            1     0     1 12752 42-22:27:38 /sbin/init
          812     1   812 2097152    01:02:03 php
          813   812   812   512       00:04 sleep
        junk line
          900   812   900 3145728    10:00 php worker one
        PS);

    expect($listing->uses())->toBe([
        ['pid' => 1, 'parent' => 0, 'rss' => 12752, 'age' => 3_709_658, 'name' => '/sbin/init'],
        ['pid' => 812, 'parent' => 1, 'rss' => 2_097_152, 'age' => 3_723, 'name' => 'php'],
        ['pid' => 813, 'parent' => 812, 'rss' => 512, 'age' => 4, 'name' => 'sleep'],
        ['pid' => 900, 'parent' => 812, 'rss' => 3_145_728, 'age' => 600, 'name' => 'php worker one'],
    ])->and($listing->parents())->toBe([1 => 0, 812 => 1, 813 => 812, 900 => 812])
        ->and($listing->groupOf(813))->toBe(812)
        ->and($listing->groupOf(900))->toBe(900)
        ->and(ProcessListing::printed('')->uses())->toBe([]);
});

it('asks ps for every process with its parent, group, memory, age and name, in that order', function (): void {
    expect(ProcessListing::COMMAND)->toBe(['ps', '-A', '-o', 'pid=', '-o', 'ppid=', '-o', 'pgid=', '-o', 'rss=', '-o', 'etime=', '-o', 'comm=']);
});

it('reads each process from its stat file: its name up to the last parenthesis, its parent, group, age and memory', function (): void {
    $listing = ProcessListing::stated([
        processListingStat(1, 'systemd', 0, 1, 10, 3188),
        processListingStat(4242, 'a) S 1 1 (b', 1, 4242, 150_000, 256),
        processListingStat(4243, 'php worker', 4242, 4242, 160_000, 1),
    ], 2_000.75, 100, 16_384);

    expect($listing->uses())->toBe([
        ['pid' => 1, 'parent' => 0, 'rss' => 51_008, 'age' => 2_000, 'name' => 'systemd'],
        ['pid' => 4242, 'parent' => 1, 'rss' => 4_096, 'age' => 500, 'name' => 'a) S 1 1 (b'],
        ['pid' => 4243, 'parent' => 4242, 'rss' => 16, 'age' => 400, 'name' => 'php worker'],
    ])->and($listing->groupOf(4243))->toBe(4242);
});

it('leaves out a stat file that is not whole', function (string $stat): void {
    expect(ProcessListing::stated([$stat, processListingStat(7, 'sh', 1, 7, 0, 1)], 1.0, 100, 4_096)->parents())->toBe([7 => 1]);
})->with([
    'empty' => [''],
    'without a name' => ['8 S 1 8 8 0'],
    'cut short' => ['8 (sh) S 1 8 8 0 -1 4194560 120 0 0 0 1 2 0 0 20 0 1 0 10 8638464'],
]);

it('reads the last field it needs of a stat file that holds nothing after it', function (): void {
    expect(ProcessListing::stated(['8 (sh) S 1 8 8 0 -1 4194560 120 0 0 0 1 2 0 0 20 0 1 0 10 8638464 2'], 1.0, 100, 4_096)->uses())
        ->toBe([['pid' => 8, 'parent' => 1, 'rss' => 8, 'age' => 0, 'name' => 'sh']]);
});

it('finds every process under one, at any depth, each after the processes under it', function (): void {
    $listing = ProcessListing::stated([
        processListingStat(1, 'init', 0, 1, 0, 1),
        processListingStat(100, 'php', 1, 100, 0, 1),
        processListingStat(101, 'sh', 100, 100, 0, 1),
        processListingStat(102, 'sleep', 101, 100, 0, 1),
        processListingStat(103, 'sh', 100, 100, 0, 1),
        processListingStat(200, 'sh', 1, 200, 0, 1),
    ], 1.0, 100, 4_096);

    expect($listing->descendantsOf(100))->toBe([102, 101, 103])
        ->and($listing->descendantsOf(102))->toBe([])
        ->and($listing->descendantsOf(999))->toBe([]);
});

it('never counts a process under itself', function (): void {
    expect(ProcessListing::printed("0 0 0 0 00:00 idle\n5 0 5 0 00:00 init\n")->descendantsOf(0))->toBe([5]);
});

it('takes the id of a process it does not hold as its group', function (): void {
    expect(ProcessListing::printed('')->groupOf(77))->toBe(77);
});
