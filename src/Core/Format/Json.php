<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function json_encode;

/**
 * How the gate writes its own files: JSON, one key per line, slashes and
 * non-ASCII text as they are, whole-number floats with their fraction, and
 * bytes that are not UTF-8, such as a diff of a file in another encoding,
 * replaced rather than refused. A file only the gate reads, which can be
 * large, is written compactly, on one line.
 */
final readonly class Json
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @param array<mixed> $data */
    public static function encode(array $data): string
    {
        return json_encode($data, self::FLAGS | JSON_PRETTY_PRINT);
    }

    /** @param array<mixed> $data */
    public static function compact(array $data): string
    {
        return json_encode($data, self::FLAGS);
    }
}
