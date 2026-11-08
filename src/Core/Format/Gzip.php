<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function count;
use function gzencode;
use function implode;
use function inflate_add;
use function inflate_get_status;
use function inflate_init;

use InflateContext;

use function is_string;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_starts_with;

/**
 * Text as a file the gate writes compressed holds it: gzip, which every
 * store and every CI cache keeps as it is. Unpacking reads a whole gzip
 * stream, inflating it no further than a limit, and refuses anything else, a
 * stream cut short among it, and leaves whatever follows the stream's end
 * unread.
 *
 * @internal
 */
final readonly class Gzip
{
    /** Why a stream cannot be unpacked: it inflates past the most it may. */
    public const string PAST = '%s inflates to more than %d bytes.';

    /** How many compressed bytes are inflated at a time, which bounds how far past a limit one step can reach. */
    public const int STEP = 4_096;

    /** The highest level gzip packs at. */
    public const int MOST = 9;
    /** How every gzip stream begins. */
    private const string MAGIC = "\x1f\x8b";

    /** Why bytes that are not a whole gzip stream cannot be unpacked. */
    private const string NOT_GZIP = '%s is not a whole gzip stream.';

    /** Text packed; gzencode answers false only for a level or an encoding it is not given here. */
    public static function pack(string $text): string
    {
        $packed = gzencode($text);

        return is_string($packed) ? $packed : throw FormatFault::gzip();
    }

    /** Text packed at this level, or at the nearest one gzip has; gzencode answers false only as for pack(). */
    public static function packAt(string $text, int $level): string
    {
        $packed = gzencode($text, max(1, min($level, self::MOST)));

        return is_string($packed) ? $packed : throw FormatFault::gzip();
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

    /**
     * The text a gzip stream inflates to, a step at a time, stopping once it is past the most it may be or the
     * stream has ended, wherever in a step it ends. Each step's text is kept apart and joined once at the end, so
     * inflating takes time in proportion to the text, never to its square.
     */
    private static function inflated(
        InflateContext $inflating,
        string $bytes,
        string $named,
        int $most,
    ): string|CannotJudge|TooLarge {
        $parts = [];
        $length = 0;
        $steps = Bytes::chunks($bytes, self::STEP);
        $next = 0;

        while ($next < count($steps) && $length <= $most && inflate_get_status($inflating) !== ZLIB_STREAM_END) {
            $step = self::added($inflating, $steps[$next]);
            $next++;

            if ($step === false) {
                return CannotJudge::because(sprintf(self::NOT_GZIP, $named));
            }

            $parts[] = $step;
            $length += Bytes::length($step);
        }

        return match (true) {
            $length > $most => TooLarge::because(sprintf(self::PAST, $named, $most)),
            inflate_get_status($inflating) === ZLIB_STREAM_END => implode('', $parts),
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
