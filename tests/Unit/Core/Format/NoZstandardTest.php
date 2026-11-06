<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\NoZstandard;

it('packs and unpacks nothing, saying what would', function (): void {
    expect(NoZstandard::here()->isHere())->toBeFalse()
        ->and(NoZstandard::here()->pack('text', 9))->toEqual(CannotJudge::because(<<<'SAID'
            proofs.compression is zstd, and neither the PHP extension zstd nor the zstd program is here.
            Install either, or set proofs.compression to auto or gzip.
            SAID))
        ->and(NoZstandard::here()->unpackAtMost('bytes', 'The ledger', 9))->toEqual(CannotJudge::because(<<<'SAID'
            The ledger is compressed with zstd, and neither the PHP extension zstd nor the zstd program is here to read it.
            Install either, or set proofs.compression to gzip where it is written.
            SAID));
});
