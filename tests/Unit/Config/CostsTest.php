<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Costs;

it('writes what a plan spends least of, the billed minutes or the wall time', function (): void {
    expect([Costs::minutes()->written()->line(), Costs::wall()->written()->line()])
        ->toBe(['{"costs":{"mode":"minutes"}}', '{"costs":{"mode":"wall"}}']);
});
