<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\WallClock;

it('reads the system\'s monotonic clock, in nanoseconds', function (): void {
    $before = hrtime(as_number: true);
    $now = new WallClock()->nanoseconds();
    $after = hrtime(as_number: true);

    expect($now)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after);
});
