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
            ? inflate_add($inflating, $bytes, ZLIB_NO_FLUSH)
            : false;

        $whole = $inflating instanceof InflateContext && inflate_get_status($inflating) === ZLIB_STREAM_END;

        return is_string($text) && $whole ? $text : CannotJudge::because(sprintf(self::NOT_GZIP, $named));
    }
}
