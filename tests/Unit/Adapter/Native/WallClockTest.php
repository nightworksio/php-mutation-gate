<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\WallClock;

it('reads the monotonic clock in seconds: two readings in a row lie within a second, the later not before the earlier', function (): void {
    $clock = new WallClock();
    $first = $clock->seconds()->seconds();
    $second = $clock->seconds()->seconds();

    expect($second - $first)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0);
});
