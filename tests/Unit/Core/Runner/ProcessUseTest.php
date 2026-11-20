<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\ProcessUse;

$listing = static fn(): ProcessListing => ProcessListing::printed(<<<'PS'
        1     0     1 12752 42-22:27:38 /sbin/init
      812     1   812 2097152    01:02:03 php
      813   812   812   512       00:04 sleep
    junk line
      900   812   900 3145728    10:00 php worker one
    PS);

it('counts the processes the listing holds', function () use ($listing): void {
    expect(ProcessUse::of($listing())->count())->toBe(4)
        ->and(ProcessUse::of(ProcessListing::printed(''))->count())->toBe(0);
});

it('names the processes that hold the most memory, most first, in mebibytes, each with its age as ps says it', function () use ($listing): void {
    expect(ProcessUse::of($listing())->largest(2))->toBe([
        '3072 MiB  10:00  pid 900 of 812  php worker one',
        '2048 MiB  01:02:03  pid 812 of 1  php',
    ])->and(ProcessUse::of($listing())->largest(10))->toHaveCount(4)
        ->and(ProcessUse::of($listing())->largest(10)[2])->toBe('12 MiB  42-22:27:38  pid 1 of 0  /sbin/init')
        ->and(ProcessUse::of($listing())->largest(10)[3])->toBe('0 MiB  00:04  pid 813 of 812  sleep');
});

it('says an age in minutes and seconds, with hours before them from the first hour and days before those from the first day', function (string $age): void {
    expect(ProcessUse::of(ProcessListing::printed(sprintf("5 1 5 1024 %s sh\n", $age)))->largest(1))->toBe([sprintf('1 MiB  %s  pid 5 of 1  sh', $age)]);
})->with(['00:00', '00:59', '59:59', '01:00:00', '23:59:59', '1-00:00:00', '1-00:00:01', '12-03:04:05']);
