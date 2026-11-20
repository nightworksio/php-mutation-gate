<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Outputs;

it('writes whether a run counts what it cost', function (): void {
    expect([Outputs::withCosts()->written()->line(), Outputs::withoutCosts()->written()->line()])
        ->toBe(['{"outputs":{"costs":true}}', '{"outputs":{"costs":false}}']);
});

it('writes whether a run says where its minutes went, and how many of its costliest it names', function (): void {
    expect([
        Outputs::withCostReport()->written()->line(),
        Outputs::withoutCostReport()->written()->line(),
        Outputs::costliest(5)->written()->line(),
    ])->toBe([
        '{"outputs":{"costReport":true}}',
        '{"outputs":{"costReport":false}}',
        '{"outputs":{"costliest":5}}',
    ]);
});
