<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_is_list;
use function array_map;
use function is_array;
use function json_decode;
use function json_encode;
use function ksort;

use stdClass;

/**
 * JSON as a config reads and writes it: maps and lists decoded into arrays,
 * with slashes and Unicode written as they are.
 */
final readonly class Json
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    public static function decode(string $json): mixed
    {
        return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    /** Indented by four spaces, as a person reads it. */
    public static function pretty(mixed $value): string
    {
        return json_encode($value, self::FLAGS | JSON_PRETTY_PRINT);
    }

    /** One spelling for one value: every map's keys in byte order, every list as it is. */
    public static function canonical(mixed $value): string
    {
        return self::encode(self::sorted($value));
    }

    /** A map written as a JSON object, `{}` when it is empty. */
    public static function object(mixed $map): mixed
    {
        return $map === [] ? new stdClass() : $map;
    }

    /**
     * Whether a decoded value is a JSON object. An empty one decodes as an empty array.
     *
     * @phpstan-assert-if-true array<mixed> $value
     */
    public static function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(self::sorted(...), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}
