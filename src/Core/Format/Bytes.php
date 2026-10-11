<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function mb_strlen;
use function mb_strpos;
use function mb_substr;

/**
 * Text measured and cut in bytes, as a file's size, a program's output, a
 * token's offset or a limit a service sets counts it, rather than in
 * characters.
 */
final readonly class Bytes
{
    /** Bytes in a megabyte, as a size is said to a person. */
    public const int PER_MEGABYTE = 1_000_000;

    /** Bytes in PHP's `M`, as a memory_limit counts them. */
    public const int PER_MEBIBYTE = 1_048_576;

    /** The bytes a line's indentation is made of: a space and a tab. */
    public const string BLANKS = " \t";

    /** The encoding in which one character is one byte. */
    private const string ENCODING = '8bit';

    /** How many bytes the text holds. */
    public static function length(string $text): int
    {
        return mb_strlen($text, self::ENCODING);
    }

    /** Where this first occurs from a byte offset on, as a byte offset, or false where it does not. */
    public static function find(string $text, string $sought, int $from): int|false
    {
        return mb_strpos($text, $sought, $from, self::ENCODING);
    }

    /** The bytes of the text from an offset, this many of them, or as many as there are. */
    public static function slice(string $text, int $from, int $length): string
    {
        return mb_substr($text, $from, $length, self::ENCODING);
    }
}
