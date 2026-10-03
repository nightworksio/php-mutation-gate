<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Waiting;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('waits the interval it is given, and then says to go on watching', function (): void {
    $started = hrtime(as_number: true);

    $goesOn = Waiting::every(Seconds::of(0.05))();

    expect($goesOn)->toBeTrue()
        ->and(hrtime(as_number: true) - $started)->toBeGreaterThanOrEqual(50_000_000);
});
