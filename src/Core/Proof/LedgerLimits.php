<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function intdiv;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\TooLarge;

use function sprintf;

/**
 * How much of a ledger a store reads, so neither an answer without end nor a
 * small gzip stream that inflates without end can hold or end a run: twice
 * what a ledger at the retention cap measures (ADR-0013 decision 13), as
 * compressed bytes, as decompressed bytes, and the seconds a read over a
 * network may take.
 */
final readonly class LedgerLimits
{
    /** Twice the 5,486,948 bytes a ledger of 20,000 proofs measures gzipped. */
    private const int PACKED = 11_000_000;

    /** Twice the 18,830,752 bytes that ledger measures decompressed. */
    private const int UNPACKED = 38_000_000;

    /** The longest one read over a network may take, in seconds. */
    private const float SECONDS = 60.0;

    private function __construct(private int $packed, private int $unpacked, private float $seconds)
    {
    }

    public static function standard(): self
    {
        return new self(self::PACKED, self::UNPACKED, self::SECONDS);
    }

    public static function of(int $packed, int $unpacked, float $seconds): self
    {
        return new self($packed, $unpacked, $seconds);
    }

    /** Whether a ledger of this many compressed bytes is read. */
    public function admitsPacked(int $bytes): bool
    {
        return $bytes <= $this->packed;
    }

    /** The most compressed bytes a ledger is read at. */
    public function packed(): int
    {
        return $this->packed;
    }

    /**
     * The text a ledger's bytes inflate to, within these limits; or why there is none: they are past the
     * compressed limit, which leaves them uninflated, or inflate past the other, or are no whole gzip stream.
     */
    public function inflated(string $bytes, string $named): string|CannotJudge|TooLarge
    {
        return $this->admitsPacked(Bytes::length($bytes))
            ? Gzip::unpackAtMost($bytes, $named, $this->unpacked)
            : TooLarge::because($this->pastPacked());
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    /** Whether a ledger written as this text, gzipped to these bytes, is one a run reads. */
    public function admitsWritten(string $text, string $bytes): bool
    {
        return $this->admitsPacked(Bytes::length($bytes)) && Bytes::length($text) <= $this->unpacked;
    }

    /**
     * Fewer proofs than a ledger of this many, written as this text and these bytes, holds: as many as fit in
     * proportion, so the next one written is likely within the limits.
     */
    public function fitting(int $proofs, string $text, string $bytes): int
    {
        $packed = intdiv($proofs * $this->packed, max(1, Bytes::length($bytes)));
        $unpacked = intdiv($proofs * $this->unpacked, max(1, Bytes::length($text)));

        return max(0, min($proofs - 1, $packed, $unpacked));
    }

    /** Why a ledger past the compressed limit is not read. */
    public function pastPacked(): string
    {
        return sprintf('it is larger than %d bytes', $this->packed);
    }
}
