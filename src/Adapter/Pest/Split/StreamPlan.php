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
 * What a split stream is told to do (ADR-0117), read from the plan Pest's own
 * process wrote: where it writes its results, each site's mutant, likely
 * killer and variables, the source file each schema stands for, each test's
 * own time by the coverage map, how many children it holds at once and each
 * child's limit.
 *
 * @internal
 */
final readonly class StreamPlan
{
    /**
     * @param array<string, array<int, array{string, string, array<string, string>}>> $sites each site's mutant, killer
     *                                                                                        and variables, by file key
     *                                                                                        and switch id
     * @param array<string, string>                                                   $paths each schema's source file,
     *                                                                                        by its file key
     * @param array<string, float>                                                    $times each test's own seconds
     */
    public function __construct(
        public string $results,
        public array $sites,
        public array $paths,
        public array $times,
        public int $children,
        public ChildLimit $limit,
    ) {
    }

    /** The plan this file holds, or false where it holds none. */
    public static function read(string $file): self|false
    {
        $read = is_file($file) ? json_decode((string) file_get_contents($file), associative: true) : null;

        if (! is_array($read) || ! self::holds($read, 'results') || ! is_string($read['results'])) {
            return false;
        }

        return new self(
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
            foreach (is_array($switches) ? $switches : [] as $id => $site) {
                $mutant = is_array($site) && array_key_exists(0, $site) ? $site[0] : null;
                $killer = is_array($site) && array_key_exists(1, $site) ? $site[1] : null;

                if (is_string($mutant) && is_string($killer)) {
                    $read[(string) $file][(int) $id] = [$mutant, $killer, self::textsOf(self::listed($site, '2'))];
                }
            }
        }

        return $read;
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
