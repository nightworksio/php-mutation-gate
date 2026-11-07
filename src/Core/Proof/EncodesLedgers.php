<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * A proof store that packs the ledgers it writes, and reads those it reads,
 * as a codec says (ADR-0085), which the run sets from `proofs.compression`.
 *
 * @internal
 */
interface EncodesLedgers
{
    /** This store, writing ledgers packed as this codec says, and reading them as it reads them. */
    public function encoding(LedgerCodec $codec): static;

    /** This store, reading and writing ledgers within these limits, and keeping what they keep. */
    public function limitedTo(LedgerLimits $limits): static;
}
