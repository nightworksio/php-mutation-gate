<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\Config\Retained;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;

/**
 * How large a kept coverage map may be, each limit twice what the gate's own
 * suite's whole map measures: 13,118 tests and 8,012,128 covered line-test
 * pairs. A map past either is not kept, which costs the next run a full
 * measure and never a verdict. A read over a network takes as long as a
 * ledger's may, since it is the same read.
 *
 * @internal
 */
final readonly class MapLimits
{
    /** Twice the 2,058,884 bytes that map measures gzipped. */
    public const int PACKED = 4_200_000;

    /** Twice the 42,415,890 bytes it measures decompressed. */
    public const int UNPACKED = 85_000_000;

    private function __construct(private int $packed, private int $unpacked)
    {
    }

    public static function standard(): self
    {
        return new self(self::PACKED, self::UNPACKED);
    }

    public static function of(int $packed, int $unpacked): self
    {
        return new self($packed, $unpacked);
    }

    /** What `proofs.limits.map` says. */
    public static function kept(Retained $kept): self
    {
        return new self($kept->mapPacked(), $kept->mapUnpacked());
    }

    /** Whether a map written as this text, gzipped to these bytes, is one a store keeps. */
    public function admits(string $text, string $bytes): bool
    {
        return Bytes::length($bytes) <= $this->packed && Bytes::length($text) <= $this->unpacked;
    }

    /** The most bytes a map's text decompresses to before it is refused. */
    public function unpacked(): int
    {
        return $this->unpacked;
    }

    /** The most compressed bytes a kept map is. */
    public function packed(): int
    {
        return $this->packed;
    }

    /** What a store reads of a kept map at most: these limits, within the time a ledger's read may take. */
    public function reading(): LedgerLimits
    {
        return LedgerLimits::of($this->packed, $this->unpacked, LedgerLimits::standard()->seconds());
    }
}
