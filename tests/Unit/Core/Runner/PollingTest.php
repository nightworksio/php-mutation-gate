<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('looks at a running process a hundred times a second', function (): void {
    expect(Polling::interval())->toEqual(Seconds::of(0.01));
});
