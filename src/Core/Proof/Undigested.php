<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/** A proof that records no digest of its inputs, as a ledger of an earlier format holds it: it carries nothing. */
final readonly class Undigested
{
    public static function proof(): self
    {
        return new self();
    }
}
