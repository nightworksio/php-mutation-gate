<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function file;
use function glob;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function str_starts_with;
use function unlink;

/**
 * The coverage map a run's streams recorded (ADR-0135): each line of the
 * project each test executed in a stream's parent, and each test's own time
 * there, from every `recorded-*.jsonl` of a directory.
 * A file outside the project is left out.
 *
 * @internal
 */
final readonly class RecordedMap
{
    /** Every stream's recorded lines in a directory, by the directory. */
    public const string RECORDED = '%s/recorded-*.jsonl';

    /**
     * The map recorded in a directory; none where its streams recorded
     * nothing. Where the tests that ran whole are asked for alone, a test
     * that ran in a process of its own is left out, as one a stream handed
     * to a forked process always is.
     */
    public static function in(string $directory, Root $root, bool $wholeOnly = false): CoverageMap|NotGiven
    {
        $map = CoverageMap::of();
        $files = glob(sprintf(self::RECORDED, $directory)) ?: [];

        if ($files === []) {
            return NotGiven::value();
        }

        foreach ($files as $file) {
            foreach (file($file) ?: [] as $text) {
                $map = self::with($map, json_decode($text, associative: true), $root, $wholeOnly);
            }
        }

        return $map;
    }

    /** Removes every file of lines a directory's streams recorded, so a later run there starts with none. */
    public static function cleared(string $directory): void
    {
        foreach (glob(sprintf(self::RECORDED, $directory)) ?: [] as $file) {
            unlink($file);
        }
    }

    private static function with(CoverageMap $map, mixed $line, Root $root, bool $wholeOnly): CoverageMap
    {
        if (! is_array($line) || ($line[StreamLine::KIND] ?? '') !== StreamLine::COVERED) {
            return $map;
        }

        if ($wholeOnly && ($line[StreamLine::OWN] ?? false) === true) {
            return $map;
        }

        if (! is_string($line['t'] ?? null)) {
            return $map;
        }

        $test = TestId::of($line['t']);

        foreach (is_array($line['l'] ?? null) ? $line['l'] : [] as $file => $lines) {
            $inside = is_string($file) && str_starts_with($file, $root->value());

            foreach ($inside && is_array($lines) ? $lines : [] as $number) {
                $map = is_int($number) && $number > 0
                    ? $map->covered($root->relative($file), Line::of($number), $test)
                    : $map;
            }
        }

        $seconds = $line['s'] ?? null;

        return is_float($seconds) || is_int($seconds) ? $map->timed($test, Seconds::of((float) $seconds)) : $map;
    }
}
