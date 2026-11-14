<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;

use function sodium_base642bin;

use const SODIUM_BASE64_VARIANT_ORIGINAL;

use function sodium_bin2base64;

use SodiumException;

/**
 * A proof written whole, as the gzipped file of a ledger holding that proof
 * alone at the base it was established at, in base64, so that it reads as a
 * ledger's proof reads: an entry of a pack of `outbreak cache` (ADR-0131), and
 * a proof `outbreak serve` keeps under its key (ADR-0133).
 *
 * @internal the shape of an entry, which the outbreak binary keeps without reading it
 */
final readonly class ProofEntry
{
    public static function text(Proof $proof): string
    {
        $ledger = LedgerFile::encode(Ledger::empty()->atBase($proof->run()->base())->withProof($proof));

        return sodium_bin2base64($ledger, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /** The proof an entry holds under this key; or none where it holds none, or is no entry. */
    public static function read(Digest $key, string $text): Proof|Unproved
    {
        try {
            $ledger = sodium_base642bin($text, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            return Unproved::key($key);
        }

        return LedgerFile::decode($ledger)->proofs()->proofFor($key);
    }
}
