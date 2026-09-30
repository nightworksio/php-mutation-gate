<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function is_array;
use function json_decode;

/** A JSON text a test reads back, one place at a time. */
final class Decoded
{
    /** What a JSON text holds at a path of keys; null where it holds nothing there. */
    public static function at(string $json, string|int ...$keys): mixed
    {
        $value = json_decode($json, associative: true);

        foreach ($keys as $key) {
            $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : null;
        }

        return $value;
    }

    /**
     * The value under a key of every item of the list at a path of keys.
     *
     * @return list<mixed>
     */
    public static function column(string $json, string $key, string|int ...$keys): array
    {
        $items = self::at($json, ...$keys);
        $column = [];

        foreach (is_array($items) ? $items : [] as $item) {
            $column[] = is_array($item) && array_key_exists($key, $item) ? $item[$key] : null;
        }

        return $column;
    }
}
