<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\NoZstandard;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Format\Zstandard;

use function str_starts_with;

/**
 * How a ledger's file is compressed and read back (ADR-0085): written as
 * `proofs.compression` says at `proofs.compressionLevel`, `auto` being zstd
 * where this machine offers it and gzip where it does not; read as its
 * first bytes say, gzip or zstd, whatever wrote it.
 *
 * @internal
 */
final readonly class LedgerCodec
{
    /** `proofs.compressionLevel` where nothing says: zstd's 9, gzip's highest. */
    public const int LEVEL = 9;

    /** The highest `proofs.compressionLevel`: zstd's highest without `--ultra`. */
    public const int MOST_LEVEL = 19;

    /** How every zstd frame begins. */
    private const string ZSTD = "\x28\xb5\x2f\xfd";

    private function __construct(private Compression $compression, private int $level, private Zstandard $zstd)
    {
    }

    /** Written as gzip, and read as gzip alone, where nothing offers zstd. */
    public static function gzipOnly(): self
    {
        return new self(Compression::Gzip, self::LEVEL, NoZstandard::here());
    }

    /** Written as this compression says at this level, with the zstd this machine offers. */
    public static function of(Compression $compression, int $level, Zstandard $zstd): self
    {
        return new self($compression, $level, $zstd);
    }

    /** A ledger's text, packed; or why it cannot be: zstd named, and none here. */
    public function pack(string $text): string|CannotJudge
    {
        return match ($this->compression) {
            Compression::Gzip => Gzip::packAt($text, $this->level),
            Compression::Zstd => $this->zstd->pack($text, $this->level),
            Compression::Auto => $this->packedAsAvailable($text),
        };
    }

    /** The text a ledger's file holds, inflated no further than so many bytes, gzip or zstd by its first bytes. */
    public function unpackAtMost(string $bytes, string $named, int $most): string|CannotJudge|TooLarge
    {
        return str_starts_with($bytes, self::ZSTD)
            ? $this->zstd->unpackAtMost($bytes, $named, $most)
            : Gzip::unpackAtMost($bytes, $named, $most);
    }

    /** A ledger's text, packed with zstd, or with gzip where zstd cannot pack it. */
    private function packedAsAvailable(string $text): string
    {
        $packed = $this->zstd->pack($text, $this->level);

        return is_string($packed) ? $packed : Gzip::packAt($text, $this->level);
    }
}
