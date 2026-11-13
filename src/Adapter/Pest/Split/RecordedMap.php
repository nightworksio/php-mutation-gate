<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function file;
use function glob;
use function is_array;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
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
        $texts = self::texts($directory);

        foreach ($texts as $text) {
            $map = self::with($map, RecordedLine::of($text), $root, $wholeOnly);
        }

        return self::files($directory) === [] ? NotGiven::value() : $map;
    }

    /** Removes every file of lines a directory's streams recorded, so a later run there starts with none. */
    public static function cleared(string $directory): void
    {
        foreach (self::files($directory) as $file) {
            unlink($file);
        }
    }

    /** @return list<string> each line every stream recorded in a directory */
    public static function texts(string $directory): array
    {
        $texts = [];

        foreach (self::files($directory) as $file) {
            $lines = file($file);
            $texts = is_array($lines) ? [...$texts, ...$lines] : $texts;
        }

        return $texts;
    }

    /** @return list<string> every file of lines a directory's streams recorded */
    private static function files(string $directory): array
    {
        $files = glob(sprintf(self::RECORDED, $directory));

        return is_array($files) ? $files : [];
    }

    private static function with(CoverageMap $map, RecordedLine $line, Root $root, bool $wholeOnly): CoverageMap
    {
        if (! $line->covered || ($wholeOnly && $line->own) || $line->test === '') {
            return $map;
        }

        $test = TestId::of($line->test);

        foreach ($line->lines as $file => $numbers) {
            $map = str_starts_with($file, $root->value())
                ? self::covered($map, $root->relative($file), $numbers, $test)
                : $map;
        }

        return $line->seconds instanceof NotGiven ? $map : $map->timed($test, Seconds::of($line->seconds));
    }

    /** @param list<int> $numbers */
    private static function covered(CoverageMap $map, Path $file, array $numbers, TestId $test): CoverageMap
    {
        foreach ($numbers as $number) {
            $map = $number > 0 ? $map->covered($file, Line::of($number), $test) : $map;
        }

        return $map;
    }
}
