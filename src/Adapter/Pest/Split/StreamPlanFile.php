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
            self::sitesOf(self::listed($read, 'sites')),
            self::textsOf(self::listed($read, 'paths')),
            self::timesOf(self::listed($read, 'times')),
            max(1, self::holds($read, 'children') && is_int($read['children']) ? $read['children'] : 1),
            new ChildLimit(
                self::number($read, 'multiple'),
                self::number($read, 'floor'),
                self::number($read, 'longest'),
            ),
        );
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
    private static function number(array $read, string $key): float
    {
        $value = self::holds($read, $key) ? $read[$key] : 0;

        return is_float($value) || is_int($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<mixed>                                                               $sites
     * @return array<string, array<int, array{string, string, array<string, string>}>>
     */
    private static function sitesOf(array $sites): array
    {
        $read = [];

        foreach ($sites as $file => $switches) {
            $read[(string) $file] = is_array($switches) ? self::switchesOf($switches) : [];
        }

        return $read;
    }

    /**
     * @param  array<mixed>                                                $switches
     * @return array<int, array{string, string, array<string, string>}>
     */
    private static function switchesOf(array $switches): array
    {
        $read = [];

        foreach ($switches as $id => $site) {
            $planned = is_array($site) ? self::siteOf($site) : false;

            if ($planned !== false) {
                $read[(int) $id] = $planned;
            }
        }

        return $read;
    }

    /**
     * @param  array<mixed>                                      $site
     * @return array{string, string, array<string, string>}|false
     */
    private static function siteOf(array $site): array|false
    {
        $mutant = array_key_exists(0, $site) ? $site[0] : null;
        $killer = array_key_exists(1, $site) ? $site[1] : null;

        return is_string($mutant) && is_string($killer)
            ? [$mutant, $killer, self::textsOf(self::listed($site, '2'))]
            : false;
    }

    /**
     * @param  array<mixed>          $texts
     * @return array<string, string>
     */
    private static function textsOf(array $texts): array
    {
        $read = [];

        foreach ($texts as $name => $value) {
            if (is_string($value)) {
                $read[(string) $name] = $value;
            }
        }

        return $read;
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
