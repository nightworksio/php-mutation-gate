<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Proofs;

it('writes whether a run takes its own scope\'s proofs', function (): void {
    expect([Proofs::ownScope()->written()->line(), Proofs::defaultBranchOnly()->written()->line()])
        ->toBe(['{"proofs":{"ownScope":true}}', '{"proofs":{"ownScope":false}}']);
});

it('writes how a ledger is compressed, and at which level', function (): void {
    expect([Proofs::compression('zstd')->written()->line(), Proofs::compressionLevel(15)->written()->line()])
        ->toBe(['{"proofs":{"compression":"zstd"}}', '{"proofs":{"compressionLevel":15}}']);
});
