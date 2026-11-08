<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function count;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * A ledger as it is written: what its limits' retention keeps,
 * packed as its codec says, and within the limits a run reads a ledger to (ADR-0013).
 * Retention caps proofs, not bytes, so a project whose units hold many
 * mutants can reach the limits first; then the oldest proofs of those kept
 * are dropped until the ledger is within them, and the run says so.
 *
 * @internal
 */
final readonly class EncodedLedger
{
    private const string TRIMMED = 'It keeps the newest %d of %d proofs, so a run can still read the ledger.';

    private function __construct(private string $bytes, private int $kept, private int $retained)
    {
    }

    /**
     * This ledger, packed as the codec says, with the oldest proofs dropped until it is within these limits; or
     * why it cannot be packed.
     */
    public static function within(Ledger $ledger, LedgerLimits $limits, LedgerCodec $codec): self|CannotJudge
    {
        $retention = $limits->retention();
        $retained = count($retention->proofsOf($ledger));
        $kept = $retained;
        $text = LedgerJson::text($ledger, $retention);
        $bytes = $codec->pack($text);

        while (is_string($bytes) && $kept > 0 && ! $limits->admitsWritten($text, $bytes)) {
            $kept = $limits->fitting($kept, $text, $bytes);
            $text = LedgerJson::text($ledger, $retention->keepingAtMost($kept));
            $bytes = $codec->pack($text);
        }

        return is_string($bytes) ? new self($bytes, $kept, $retained) : $bytes;
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
