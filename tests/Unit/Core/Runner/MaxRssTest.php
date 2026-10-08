<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\MaxRss;

it('reads ru_maxrss in bytes on macOS, and in kilobytes elsewhere', function (): void {
    expect(MaxRss::bytes(2048, 'Darwin'))->toBe(2048)
        ->and(MaxRss::bytes(2048, 'Linux'))->toBe(2048 * 1024)
        ->and(MaxRss::bytes(2048, 'BSD'))->toBe(2048 * 1024);
});
