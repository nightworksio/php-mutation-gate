<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\CoreTimes;
use NightWorksIO\MutationGate\Core\Runner\Remaining;

it('says each part the system said in gigabytes, and nothing of a machine that said none', function (): void {
    expect(Remaining::unknown()->text())->toBe('')
        ->and(Remaining::unknown()->withDisk(2_550_000_000)->text())->toBe('disk 2.5 GB free')
        ->and(Remaining::unknown()->withSwap(1_000_000_000, 4_000_000_000)->text())->toBe('swap 1.0 of 4.0 GB used')
        ->and(Remaining::unknown()->withMemory(500_000_000, 8_000_000_000)->withDisk(0)->text())
        ->toBe('memory 0.5 of 8.0 GB free, disk 0.0 GB free');
});

it('says how many cores were busy since an earlier reading, after what the machine has left', function (): void {
    $earlier = Remaining::unknown()->withCores(CoreTimes::of(1_000, 4_000, 4));
    $later = Remaining::unknown()->withDisk(2_550_000_000)->withCores(CoreTimes::of(1_300, 4_400, 4));

    expect($later->since($earlier))->toBe('disk 2.5 GB free, cores 3.0 of 4 busy')
        ->and(Remaining::unknown()->withCores(CoreTimes::of(1_000, 4_000, 4))->since($earlier))->toBe('')
        ->and($later->since(Remaining::unknown()))->toBe('disk 2.5 GB free')
        ->and(Remaining::unknown()->since($earlier))->toBe('')
        ->and($later->text())->toBe('disk 2.5 GB free');
});
