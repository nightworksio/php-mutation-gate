<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * How a ledger's file is compressed, `proofs.compression` (ADR-0085):
 * zstd where this machine offers it and gzip where it does not, or one of
 * them by name. Reading tells each by its first bytes, whatever wrote it.
 */
enum Compression: string
{
    case Auto = 'auto';

    case Gzip = 'gzip';

    case Zstd = 'zstd';
}
