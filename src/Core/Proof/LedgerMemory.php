<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function intdiv;

use NightWorksIO\MutationGate\Core\Format\Bytes;

/**
 * The memory the gate's own process needs to read and write ledgers as large
 * as a run reads (ADR-0013 decision 13): what PHP's own default gives the
 * rest of a run, and so many bytes for each byte of that largest ledger,
 * decompressed. A run holds at most two ledgers, the default branch's and
 * its own, and writes one.
 */
final readonly class LedgerMemory
{
    /** What the rest of a run is given: PHP's own default memory_limit, 128M. */
    private const int REST = 128 * Bytes::PER_MEBIBYTE;

    /**
     * Bytes of memory per decompressed byte of ledger, from the heaviest shape measured: a ledger of kills alone,
     * each killed by one test of its own, its names short, held in 13.45, read at a peak of 15.09 and written in
     * 4.09. Two held and one written, the most a run takes, is 30.99, and a fifth more is 37.19.
     */
    private const int PER_BYTE = 38;

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

    /** The bytes of memory a run may need, as the least whole number of PHP's `M` that holds them. */
    public function mebibytes(): int
    {
        return intdiv($this->bytes() + Bytes::PER_MEBIBYTE - 1, Bytes::PER_MEBIBYTE);
    }

    /** Whether a memory_limit of this many bytes, or none at all as -1, gives a run what it may need. */
    public function admits(int $limit): bool
    {
        return $limit < 0 || $limit >= $this->bytes();
    }
}
