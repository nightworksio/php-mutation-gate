<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function max;
use function mb_str_split;
use function mb_strlen;
use function mb_substr;
use function strpos;
use function strrpos;

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

    /** Bytes in a gigabyte, as a size is said to a person. */
    public const int PER_GIGABYTE = 1_000_000_000;

    /** Bytes in a kibibyte, as Linux's `/proc` counts memory. */
    public const int PER_KIBIBYTE = 1_024;

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
        return strpos($text, $sought, $from);
    }

    /** Where this last occurs wholly before a byte offset, as a byte offset, or false where it does not. */
    public static function findBefore(string $text, string $sought, int $before): int|false
    {
        $last = $before - self::length($sought);

        return $last < 0 ? false : strrpos($text, $sought, $last - self::length($text));
    }

    /** Where the line holding a byte offset starts: past the newline before it, or at the text's start. */
    public static function lineStartOf(string $text, int $offset): int
    {
        $newline = self::findBefore($text, "\n", $offset);

        return $newline === false ? 0 : $newline + 1;
    }

    /** Where the line holding a byte offset ends: at the newline from it on, or at the text's end. */
    public static function lineEndOf(string $text, int $offset): int
    {
        $newline = self::find($text, "\n", $offset);

        return $newline === false ? self::length($text) : $newline;
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
