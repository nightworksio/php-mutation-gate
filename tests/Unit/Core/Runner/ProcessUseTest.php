<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\ProcessUse;

$listing = <<<'PS'
        1     0  12752 42-22:27:38 /sbin/init
      812     1 2097152    01:02:03 php
      813   812    512       00:04 sleep
    junk line
      900   812 3145728    10:00 php worker one
    PS;

it('lists every process ps prints with its parent, memory, age and name, the parent listing\'s columns first', function (): void {
    expect(ProcessUse::LISTING)->toBe([...ProcessTable::LISTING, '-orss=', '-oetime=', '-ocomm=']);
});

it('counts the processes the listing names, skipping a line that names none', function () use ($listing): void {
    expect(ProcessUse::parse($listing)->count())->toBe(4)
        ->and(ProcessUse::parse('')->count())->toBe(0);
});

it('names the processes that hold the most memory, most first, in mebibytes', function () use ($listing): void {
    expect(ProcessUse::parse($listing)->largest(2))->toBe([
        '3072 MiB  10:00  pid 900 of 812  php worker one',
        '2048 MiB  01:02:03  pid 812 of 1  php',
    ])->and(ProcessUse::parse($listing)->largest(10))->toHaveCount(4)
        ->and(ProcessUse::parse($listing)->largest(10)[3])->toBe('0 MiB  00:04  pid 813 of 812  sleep');
});
