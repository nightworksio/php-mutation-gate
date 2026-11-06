<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

/**
 * No Zstandard on this machine: it packs and unpacks nothing, and says
 * what would.
 *
 * @internal
 */
final readonly class NoZstandard implements Zstandard
{
    /** Why a stream cannot be packed or unpacked: neither the extension nor the program is here. */
    public const string ABSENT = <<<'SAID'
        %s is compressed with zstd, and neither the PHP extension zstd nor the zstd program is here to read it.
        Install either, or set proofs.compression to gzip where it is written.
        SAID;

    private const string NOT_PACKED = <<<'SAID'
        proofs.compression is zstd, and neither the PHP extension zstd nor the zstd program is here.
        Install either, or set proofs.compression to auto or gzip.
        SAID;

    public static function here(): self
    {
        return new self();
    }

    public function pack(string $text, int $level): CannotJudge
    {
        return CannotJudge::because(self::NOT_PACKED);
    }

    public function unpackAtMost(string $bytes, string $named, int $most): CannotJudge
    {
        return CannotJudge::because(sprintf(self::ABSENT, $named));
    }

    public function isHere(): bool
    {
        return false;
    }
}
