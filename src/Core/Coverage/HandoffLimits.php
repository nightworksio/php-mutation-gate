<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function ini_parse_quantity;
use function intdiv;
use function is_string;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\TooLarge;

use function sprintf;

/**
 * How much of a coverage map one job hands another is read, so a small gzip
 * stream that inflates without end, which a coverage run of the code under
 * test can leave, costs a shard a map it cannot judge by and never the run.
 * The compressed bytes have a ceiling, of which a reader takes no more than
 * one past. The decompressed bytes are what the reading process's memory
 * holds, since reading a map takes 23 bytes for each of them, and no more
 * than so many times the compressed bytes.
 */
final readonly class HandoffLimits
{
    /** The most compressed bytes a handed map is read at. */
    private const int PACKED = 256_000_000;

    /** Bytes of memory reading a map takes for each byte it decompresses to: 436 MB for a map of 18.9 MB. */
    private const int PER_BYTE = 23;

    /** The most decompressed bytes where the process has no memory limit, a gibibyte: 23 of them to read. */
    private const int UNLIMITED = 1_024 * Bytes::PER_MEBIBYTE;

    /**
     * The most times its compressed bytes a map decompresses to: twice the 153 a map measures where every test
     * covers every line, which repeats most, and far below the 1,032 of a stream that repeats one byte.
     */
    private const int RATIO = 300;

    /** Why a map past the compressed limit is not read. */
    private const string PAST_PACKED = '%s is larger than %d bytes, so no line of it is read.';

    private function __construct(private int $packed, private int $unpacked, private int $ratio)
    {
    }

    /** The limits for a process with this `memory_limit`, as `ini_get` answers it; -1, or none, is no limit. */
    public static function under(string|false $memoryLimit): self
    {
        $memory = is_string($memoryLimit) ? ini_parse_quantity($memoryLimit) : -1;

        return new self(self::PACKED, $memory < 0 ? self::UNLIMITED : intdiv($memory, self::PER_BYTE), self::RATIO);
    }

    public static function of(int $packed, int $unpacked, int $ratio): self
    {
        return new self($packed, $unpacked, $ratio);
    }

    /** The most compressed bytes a map is read at. */
    public function packed(): int
    {
        return $this->packed;
    }

    /** The most decompressed bytes a map is read at, whatever the ratio to its compressed bytes allows. */
    public function unpacked(): int
    {
        return $this->unpacked;
    }

    /**
     * The most bytes a reader takes of a map's file: one past the limit, so that a file past it is known to be.
     *
     * @return positive-int
     */
    public function readable(): int
    {
        return max(0, $this->packed) + 1;
    }

    /**
     * The text a map's bytes inflate to, within these limits; or why there is none: they are past the compressed
     * limit, which leaves them uninflated, or inflate past the memory's share or past the ratio to them, or are
     * no whole gzip stream.
     */
    public function inflated(string $bytes): string|CannotJudge|TooLarge
    {
        $packed = Bytes::length($bytes);

        return $packed <= $this->packed
            ? Gzip::unpackAtMost($bytes, CoverageMapFile::NAMED, min($this->unpacked, $this->ratio * $packed))
            : TooLarge::because(sprintf(self::PAST_PACKED, CoverageMapFile::NAMED, $this->packed));
    }
}
