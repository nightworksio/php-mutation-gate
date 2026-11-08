<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Outputs;

it('writes whether a run counts what it cost', function (): void {
    expect([Outputs::withCosts()->written()->line(), Outputs::withoutCosts()->written()->line()])
        ->toBe(['{"outputs":{"costs":true}}', '{"outputs":{"costs":false}}']);
});
