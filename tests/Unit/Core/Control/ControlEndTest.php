<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\ControlEnd;

it('names each way a control ends', function (): void {
    expect(array_map(static fn(ControlEnd $end): string => $end->value, ControlEnd::cases()))
        ->toBe(['passed', 'failed', 'ran-out', 'out-of-memory', 'unrun']);
});
