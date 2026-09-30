<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\Pause;

it('waits in real time, and not at all for no seconds', function (): void {
    $before = hrtime(as_number: true);
    Pause::for(0);

    expect(hrtime(as_number: true) - $before)->toBeLessThan(1_000_000_000);
});
