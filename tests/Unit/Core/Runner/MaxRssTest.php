<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MaxRss;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

it('reads ru_maxrss in bytes on macOS, and in kilobytes elsewhere', function (): void {
    expect(MaxRss::bytes(2048, 'Darwin'))->toBe(2048)
        ->and(MaxRss::bytes(2048, 'Linux'))->toBe(2048 * 1024)
        ->and(MaxRss::bytes(2048, 'BSD'))->toBe(2048 * 1024);
});

it('gives a peak of what ru_maxrss counts, and none where it counts none', function (): void {
    expect([MaxRss::peak(1, 'Darwin'), MaxRss::peak(3, 'Linux'), MaxRss::peak(0, 'Linux')])
        ->toEqual([MemoryCap::atLeast(1), MemoryCap::atLeast(3 * 1024), NotGiven::value()]);
});
