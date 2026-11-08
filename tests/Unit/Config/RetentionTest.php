<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Retention;

it('writes how many mutants keep their killers, or that every one does', function (): void {
    expect([Retention::killers(500)->written()->line(), Retention::allKillers()->written()->line()])
        ->toBe(['{"proofs":{"keep":{"killers":500}}}', '{"proofs":{"keep":{"killers":"all"}}}']);
});
