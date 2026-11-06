<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Zstd;

use function count;
use function function_exists;
use function implode;
use function is_string;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Format\Zstandard;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function zstd_compress;
use function zstd_uncompress_add;
use function zstd_uncompress_init;

/**
 * Zstandard as PHP's zstd extension offers it, where it is loaded.
 * Unpacking feeds a stream a step at a time and stops soon past a limit,
 * which takes the extension's incremental functions, so an older extension
 * without them is taken as not here.
 *
 * @internal
 */
final readonly class ZstdExtension implements Zstandard
{
    private const string BROKEN = '%s is not a whole zstd stream.';

    private const string UNPACKED = 'The PHP extension zstd could not pack the text.';

    public static function loaded(): self
    {
        return new self();
    }

    public function pack(string $text, int $level): string|CannotJudge
    {
        $packed = zstd_compress($text, max(1, min($level, LedgerCodec::MOST_LEVEL)));

        return is_string($packed) ? $packed : CannotJudge::because(self::UNPACKED);
    }

    public function unpackAtMost(string $bytes, string $named, int $most): string|CannotJudge|TooLarge
    {
        return $this->inflated(zstd_uncompress_init(), $bytes, $named, $most);
    }

    /** Whether the extension is loaded, at a version that reads a stream a step at a time (0.13 or later). */
    public function isHere(): bool
    {
        return function_exists('zstd_uncompress_add');
    }

    /**
     * The text a stream inflates to, a step at a time, stopping once it is past the most it may be or a step is
     * broken.
     */
    private function inflated(
        object|false $context,
        string $bytes,
        string $named,
        int $most,
    ): string|CannotJudge|TooLarge {
        $whole = true;
        $pieces = [];
        $read = 0;
        $steps = Bytes::chunks($bytes, Gzip::STEP);
        $at = 0;

        while ($whole && $at < count($steps) && $read <= $most) {
            $piece = $this->added($context, $steps[$at]);
            $at++;
            $whole = is_string($piece);

            if (is_string($piece)) {
                $pieces[] = $piece;
                $read += Bytes::length($piece);
            }
        }

        return match (true) {
            ! $whole => CannotJudge::because(sprintf(self::BROKEN, $named)),
            $read > $most => TooLarge::because(sprintf(Gzip::PAST, $named, $most)),
            default => implode('', $pieces),
        };
    }

    /**
     * The text some more of a stream inflates to; false where it is broken, which the extension also raises as a
     * warning, or where the extension could not start the stream. The answer says so, so the warning is not raised.
     */
    private function added(object|false $context, string $bytes): string|false
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return $context === false ? false : zstd_uncompress_add($context, $bytes);
        } finally {
            restore_error_handler();
        }
    }
}
