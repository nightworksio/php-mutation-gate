<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function max;
use function mb_str_split;
use function mb_strlen;
use function mb_strpos;
use function mb_substr;

/**
 * Text measured and cut in bytes, as a file's size, a program's output, a
 * token's offset or a limit a service sets counts it, rather than in
 * characters.
 *
 * @internal
 */
final readonly class Bytes
{
    /** Bytes in a megabyte, as a size is said to a person. */
    public const int PER_MEGABYTE = 1_000_000;

    /** Bytes in PHP's `M`, as a memory_limit counts them. */
    public const int PER_MEBIBYTE = 1_048_576;

    /** The bytes a line's indentation is made of: a space and a tab. */
    public const string BLANKS = " \t";

    /** The byte of value zero: one no path holds, and what XOR makes of two bytes that are alike. */
    public const string NUL = "\0";

    /** The encoding in which one character is one byte. */
    private const string ENCODING = '8bit';

    /** How many bytes the text holds. */
    public static function length(string $text): int
    {
        return mb_strlen($text, self::ENCODING);
    }

    /**
     * The most bytes a reader takes to know a text is past this limit: one past it, and one for a limit below none.
     *
     * @return positive-int
     */
    public static function onePast(int $limit): int
    {
        return max(0, $limit) + 1;
    }

    /** Where this first occurs from a byte offset on, as a byte offset, or false where it does not. */
    public static function find(string $text, string $sought, int $from): int|false
    {
        return mb_strpos($text, $sought, $from, self::ENCODING);
    }

    /**
     * The text cut into pieces of this many bytes, the last of as many as are left; none of no text.
     *
     * @param  positive-int $size
     * @return list<string>
     */
    public static function chunks(string $text, int $size): array
    {
        return mb_str_split($text, $size, self::ENCODING);
    }

    /** The bytes of the text from an offset to its end. */
    public static function from(string $text, int $from): string
    {
        return mb_substr($text, $from, encoding: self::ENCODING);
    }

    /** The bytes of the text from an offset, this many of them, or as many as there are. */
    public static function slice(string $text, int $from, int $length): string
    {
        return mb_substr($text, $from, $length, self::ENCODING);
    }
}
