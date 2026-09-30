<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function gzencode;
use function inflate_add;
use function inflate_get_status;
use function inflate_init;

use InflateContext;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_starts_with;

/**
 * Text as a file the gate writes compressed holds it: gzip, which every
 * store and every CI cache keeps as it is. Unpacking reads a whole gzip
 * stream and refuses anything else, a stream cut short among it.
 */
final readonly class Gzip
{
    /** How every gzip stream begins. */
    private const string MAGIC = "\x1f\x8b";

    /** Why bytes that are not a whole gzip stream cannot be unpacked. */
    private const string NOT_GZIP = '%s is not a whole gzip stream.';

    private const string PAST = '%s inflates to more than %d bytes.';

    /** How many compressed bytes are inflated at a time, which bounds how far past a limit one step can reach. */
    private const int STEP = 4_096;

    /** Text packed; gzencode answers false only for a level or an encoding it is not given here. */
    public static function pack(string $text): string
    {
        $packed = gzencode($text);

        return is_string($packed) ? $packed : '';
    }

    /** The text some bytes hold, where they are a whole gzip stream; named as the message names them. */
    public static function unpack(string $bytes, string $named): string|CannotJudge
    {
        $inflating = inflate_init(ZLIB_ENCODING_GZIP);
        $text = $inflating instanceof InflateContext && str_starts_with($bytes, self::MAGIC)
            ? self::added($inflating, $bytes)
            : false;

        $whole = $inflating instanceof InflateContext && inflate_get_status($inflating) === ZLIB_STREAM_END;

        return is_string($text) && $whole ? $text : CannotJudge::because(sprintf(self::NOT_GZIP, $named));
    }

    /**
     * The text some bytes hold, where they are a whole gzip stream that inflates to no more than so many bytes;
     * inflated a step at a time, so a stream that inflates without end stops soon past the limit.
     */
    public static function unpackAtMost(string $bytes, string $named, int $most): string|CannotJudge|TooLarge
    {
        $inflating = inflate_init(ZLIB_ENCODING_GZIP);

        return $inflating instanceof InflateContext && str_starts_with($bytes, self::MAGIC)
            ? self::inflated($inflating, $bytes, $named, $most)
            : CannotJudge::because(sprintf(self::NOT_GZIP, $named));
    }

    /** The text a gzip stream inflates to, a step at a time, stopping once it is past the most it may be. */
    private static function inflated(
        InflateContext $inflating,
        string $bytes,
        string $named,
        int $most,
    ): string|CannotJudge|TooLarge {
        $whole = true;
        $text = '';
        $offset = 0;

        while ($whole && $offset < Bytes::length($bytes) && Bytes::length($text) <= $most) {
            $step = self::added($inflating, Bytes::slice($bytes, $offset, self::STEP));
            $whole = is_string($step);
            $text .= is_string($step) ? $step : '';
            $offset += self::STEP;
        }

        return match (true) {
            $whole && Bytes::length($text) > $most => TooLarge::because(sprintf(self::PAST, $named, $most)),
            $whole && inflate_get_status($inflating) === ZLIB_STREAM_END => $text,
            default => CannotJudge::because(sprintf(self::NOT_GZIP, $named)),
        };
    }

    /**
     * The text some more of a stream inflates to; false where it is broken, which zlib also raises as a warning.
     * The answer says so, so the warning is not raised.
     */
    private static function added(InflateContext $inflating, string $bytes): string|false
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return inflate_add($inflating, $bytes, ZLIB_NO_FLUSH);
        } finally {
            restore_error_handler();
        }
    }
}
