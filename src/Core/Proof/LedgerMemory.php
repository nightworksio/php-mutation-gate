<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * The memory the gate's own process needs to read and write ledgers as large
 * as a run reads (ADR-0013 decision 13): what PHP's own default gives the
 * rest of a run, and so many bytes for each byte of that largest ledger,
 * decompressed. A run holds at most two ledgers, the default branch's and
 * its own, and writes one.
 */
final readonly class LedgerMemory
{
    /** What the rest of a run is given: PHP's own default memory_limit, 128 MiB. */
    private const int REST = 134_217_728;

    /**
     * Bytes of memory per decompressed byte of ledger. Per byte, a ledger of the retention cap's shape is held in
     * 8.0 and read at a peak of 9.5; one of kills alone is read at a peak of 11.2 and written in 3.9. Two held
     * and one written, the most a run takes, is 19.9, and this is a fifth more.
     */
    private const int PER_BYTE = 24;

    private function __construct(private LedgerLimits $limits)
    {
    }

    public static function standard(): self
    {
        return new self(LedgerLimits::standard());
    }

    public static function within(LedgerLimits $limits): self
    {
        return new self($limits);
    }

    /** The bytes of memory a run may need. */
    public function bytes(): int
    {
        return self::REST + self::PER_BYTE * $this->limits->unpacked();
    }

    /** Whether a memory_limit of this many bytes, or none at all as -1, gives a run what it may need. */
    public function admits(int $limit): bool
    {
        return $limit < 0 || $limit >= $this->bytes();
    }
}
