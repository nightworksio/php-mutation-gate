<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Proofs;

it('writes whether coverage is measured incrementally as the PHP config calls it', function (): void {
    expect([Proofs::of(incremental: true)->php(ProjectRoot::origin()), Proofs::of(incremental: false)->php(ProjectRoot::origin())])
        ->toEqual([PhpCalls::inWith('Coverage::incremental()'), PhpCalls::inWith('Coverage::full()')]);
});
