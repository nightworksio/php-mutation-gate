<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\Pause;

it('waits in real time, a second for a second, and not at all for no seconds', function (): void {
    $before = hrtime(as_number: true);
    Pause::for(0);
    $none = hrtime(as_number: true) - $before;
    Pause::for(1);
    $one = hrtime(as_number: true) - $before - $none;

    expect($none)->toBeLessThan(1_000_000_000)
        ->and($one)->toBeGreaterThanOrEqual(1_000_000_000);
});
