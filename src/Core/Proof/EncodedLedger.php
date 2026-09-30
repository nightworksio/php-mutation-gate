<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function count;

use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * A ledger as it is written: what {@see LedgerRetention::standard()} keeps,
 * gzipped, and within the limits a run reads a ledger to (ADR-0013 decision
 * 13). Retention caps proofs, not bytes, so a project whose units hold many
 * mutants can reach the limits first; then the oldest proofs of those kept
 * are dropped until the ledger is within them, and the run says so.
 */
final readonly class EncodedLedger
{
    private const string TRIMMED = 'It keeps the newest %d of %d proofs, so a run can still read the ledger.';

    private function __construct(private string $bytes, private int $kept, private int $retained)
    {
    }

    /** This ledger, with the oldest proofs dropped until it is within these limits. */
    public static function within(Ledger $ledger, LedgerLimits $limits): self
    {
        $retention = LedgerRetention::standard();
        $retained = count($retention->proofsOf($ledger));
        $kept = $retained;
        $text = LedgerJson::text($ledger, $retention);
        $bytes = Gzip::pack($text);

        while ($kept > 0 && ! $limits->admitsWritten($text, $bytes)) {
            $kept = $limits->fitting($kept, $text, $bytes);
            $text = LedgerJson::text($ledger, $retention->keepingAtMost($kept));
            $bytes = Gzip::pack($text);
        }

        return new self($bytes, $kept, $retained);
    }

    /** The ledger's file. */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /** What a store says it wrote: where, and how many proofs it dropped to stay within the limits. */
    public function written(Written $written): Written
    {
        return $this->kept === $this->retained
            ? $written
            : Written::noting($written->where(), sprintf(self::TRIMMED, $this->kept, $this->retained));
    }
}
