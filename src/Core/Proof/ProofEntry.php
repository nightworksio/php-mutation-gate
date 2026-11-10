<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function base64_decode;
use function base64_encode;
use function is_string;

use NightWorksIO\MutationGate\Core\File\Digest;

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
        return base64_encode(LedgerFile::encode(Ledger::empty()->atBase($proof->run()->base())->withProof($proof)));
    }

    /** The proof an entry holds under this key; or none where it holds none, or is no entry. */
    public static function read(Digest $key, string $text): Proof|Unproved
    {
        $ledger = base64_decode($text, strict: true);

        return is_string($ledger) ? LedgerFile::decode($ledger)->proofs()->proofFor($key) : Unproved::key($key);
    }
}
