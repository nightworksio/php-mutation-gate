<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function json_encode;
use function str_replace;

/**
 * How the gate writes its own files: JSON, one key per line, slashes and
 * non-ASCII text as they are, whole-number floats with their fraction, and
 * bytes that are not UTF-8, such as a diff of a file in another encoding,
 * replaced rather than refused. A file only the gate reads, which can be
 * large, is written compactly, on one line.
 */
final readonly class JsonText
{
    public const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    private const string HASH = '#';

    private const string HASH_ESCAPED = '\u0023';

    /**
     * A file the gate writes, indented.
     *
     * @param array<mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode($data, self::FLAGS | JSON_PRETTY_PRINT);
    }

    /**
     * A document printed where a CI's log shows it, such as a plan, indented,
     * with each `#` written `\u0023`. Every JSON reader reads back the same
     * text, and the log reads no `##[` or `##vso[` command in it; no line of
     * JSON starts with `::`, since a key is quoted (Inert).
     *
     * @param array<mixed> $data
     */
    public static function printed(array $data): string
    {
        return self::inert(self::encode($data));
    }

    /** JSON with each `#`, which JSON holds only inside a string, written as the escape every reader decodes. */
    public static function inert(string $json): string
    {
        return str_replace(self::HASH, self::HASH_ESCAPED, $json);
    }

    /**
     * A file only the gate reads, which can be large, on one line.
     *
     * @param array<mixed> $data
     */
    public static function compact(array $data): string
    {
        return json_encode($data, self::FLAGS);
    }
}
