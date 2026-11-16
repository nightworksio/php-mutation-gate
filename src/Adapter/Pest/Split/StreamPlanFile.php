<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function max;

/**
 * A split stream's plan read from the JSON Pest's own process wrote
 * (ADR-0117), each part of it typed, and what is not as written left out.
 *
 * @internal
 */
final readonly class StreamPlanFile
{
    /** The plan this file holds, or false where it holds none. */
    public static function read(string $file): StreamPlan|false
    {
        $read = is_file($file) ? json_decode((string) file_get_contents($file), associative: true) : null;

        if (! is_array($read) || ! self::holds($read, 'results') || ! is_string($read['results'])) {
            return false;
        }

        return new StreamPlan(
            $read['results'],
            PlannedSites::of(self::listed($read, 'sites')),
            self::texts(self::listed($read, 'paths')),
            self::timesOf(self::listed($read, 'times')),
            max(1, self::integer($read, 'children', 1)),
            new ChildLimit(
                self::number($read, 'multiple'),
                self::number($read, 'floor'),
                self::number($read, 'longest'),
                max(0, self::integer($read, 'stepFloor', 0)),
            ),
            self::text($read, 'recording'),
            self::flag($read, 'bootOnce'),
            self::flag($read, 'reconnect'),
        );
    }

    /**
     * Each of these that is text, by its name.
     *
     * @param  array<mixed>          $texts
     * @return array<string, string>
     */
    public static function texts(array $texts): array
    {
        $read = [];

        foreach ($texts as $name => $value) {
            if (is_string($value)) {
                $read[(string) $name] = $value;
            }
        }

        return $read;
    }

    /** @param array<mixed> $read */
    private static function holds(array $read, string $key): bool
    {
        return array_key_exists($key, $read);
    }

    /**
     * @param  array<mixed> $read
     * @return array<mixed>
     */
    private static function listed(array $read, string $key): array
    {
        return self::holds($read, $key) && is_array($read[$key]) ? $read[$key] : [];
    }

    /** @param array<mixed> $read */
    private static function integer(array $read, string $key, int $otherwise): int
    {
        return self::holds($read, $key) && is_int($read[$key]) ? $read[$key] : $otherwise;
    }

    /** @param array<mixed> $read */
    private static function text(array $read, string $key): string
    {
        return self::holds($read, $key) && is_string($read[$key]) ? $read[$key] : '';
    }

    /** @param array<mixed> $read */
    private static function flag(array $read, string $key): bool
    {
        return self::holds($read, $key) && $read[$key] === true;
    }

    /** @param array<mixed> $read */
    private static function number(array $read, string $key): float
    {
        $value = self::holds($read, $key) ? $read[$key] : 0;

        return is_float($value) || is_int($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<mixed>          $times
     * @return array<string, float>
     */
    private static function timesOf(array $times): array
    {
        $read = [];

        foreach ($times as $test => $seconds) {
            if (is_float($seconds) || is_int($seconds)) {
                $read[(string) $test] = (float) $seconds;
            }
        }

        return $read;
    }
}
