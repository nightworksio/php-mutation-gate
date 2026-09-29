<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Composer;

use function array_key_exists;
use function is_array;
use function is_string;

/** Reading decoded JSON, whose shape nothing promises. */
final readonly class Json
{
    /** A field of an object, or an empty list where it has none. */
    public static function field(mixed $value, string $key): mixed
    {
        return is_array($value) && array_key_exists($key, $value) ? $value[$key] : [];
    }

    /**
     * The strings a value holds: itself, the strings of a list or map, or the
     * strings of the lists and maps in it.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        $strings = [];

        foreach (is_array($value) ? $value : [$value] as $entry) {
            foreach (is_array($entry) ? $entry : [$entry] as $string) {
                $strings = is_string($string) ? [...$strings, $string] : $strings;
            }
        }

        return $strings;
    }
}
