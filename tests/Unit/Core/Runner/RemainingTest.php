<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Remaining;

it('says each part the system said in gigabytes, and nothing of a machine that said none', function (): void {
    expect(Remaining::unknown()->text())->toBe('')
        ->and(Remaining::unknown()->withDisk(2_550_000_000)->text())->toBe('disk 2.5 GB free')
        ->and(Remaining::unknown()->withSwap(1_000_000_000, 4_000_000_000)->text())->toBe('swap 1.0 of 4.0 GB used')
        ->and(Remaining::unknown()->withMemory(500_000_000, 8_000_000_000)->withDisk(0)->text())
        ->toBe('memory 0.5 of 8.0 GB free, disk 0.0 GB free');
});
