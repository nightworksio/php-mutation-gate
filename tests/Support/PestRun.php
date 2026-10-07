<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function file_put_contents;
use function implode;

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rtrim;
use function sprintf;

/** What this package's Pest plugin leaves after a run: its results file, one JSON line per record. */
final readonly class PestRun
{
    /** The id of the own process a killer line comes from, unless a test names another. */
    public const int RUN = 4242;

    /** @param list<string> $lines each line as the plugin writes it, or any text a test writes in its place */
    public static function write(string $results, array $lines): void
    {
        file_put_contents($results, implode('', array_map(static fn(string $line): string => sprintf("%s\n", $line), $lines)));
    }

    /** A mutant as the plugin plans it. */
    public static function planned(
        string $id,
        string $file,
        int $line,
        string $mutator,
        string $removed,
        string $added,
    ): string {
        return self::line(RecordLine::planned(self::mutant($id, $file, $line, $mutator, $removed, $added)));
    }

    /** A mutant as Pest makes it. */
    public static function mutant(
        string $id,
        string $file,
        int $line,
        string $mutator,
        string $removed,
        string $added,
    ): PlannedMutant {
        return PlannedMutant::of(
            $id,
            DiskPath::of($file),
            Line::of($line),
            Line::of($line),
            $mutator,
            self::diff($removed, $added),
            DiskPath::of(self::mutated($id)),
        );
    }

    /** A test that failed in the own process of the mutant with this native id. */
    public static function killed(string $id, string $test): string
    {
        return self::killedAt($id, $test, Placed::unplaced(self::RUN));
    }

    /** A test that failed in the own process of the mutant with this native id, standing where it is placed. */
    public static function killedAt(string $id, string $test, Placed $placed): string
    {
        return self::line(RecordLine::killed(self::mutated($id), $test, $placed));
    }

    /** A test that errored in the own process of the mutant with this native id. */
    public static function errored(string $id, string $test): string
    {
        return self::line(RecordLine::errored(self::mutated($id), $test, Placed::unplaced(self::RUN)));
    }

    /** How the failed own process of the mutant with this native id ended, as Pest's parent saw it. */
    public static function ended(string $id, Ended $ended): string
    {
        return self::line(RecordLine::ended(self::mutated($id), $ended));
    }

    /**
     * The test files the own run of the mutant with this native id was narrowed to.
     *
     * @param list<string> $files
     */
    public static function narrowed(string $id, array $files): string
    {
        return self::line(RecordLine::narrowed(self::mutated($id), $files));
    }

    /** That the own process of the mutant with this native id had loaded its file before the mutant was in place. */
    public static function preloaded(string $id): string
    {
        return self::line(RecordLine::preloaded(self::mutated($id)));
    }

    /** That the own run of the mutant with this native id was stopped where no test finished for this long. */
    public static function silent(string $id, float $seconds): string
    {
        return self::line(RecordLine::silent(self::mutated($id), $seconds));
    }

    /** How many tests the own process of the mutant with this native id ran. */
    public static function ran(string $id, int $tests): string
    {
        return self::line(RecordLine::ran(self::mutated($id), $tests));
    }

    /** The memory limit the own process of the mutant with this native id ran out of. */
    public static function exhausted(string $id, MemoryCap $limit): string
    {
        return self::line(RecordLine::exhausted(self::mutated($id), $limit));
    }

    /** A change as Pest's diff of it reads: the line removed and the line that replaces it. */
    public static function diff(string $removed, string $added): string
    {
        return sprintf("\n  <fg=red>-        %s</>\n  <fg=green>+        %s</>\n", $removed, $added);
    }

    /** Where Pest keeps the mutated copy of the mutant with this native id. */
    public static function mutated(string $id): string
    {
        return sprintf('/tmp/mutations/%s', $id);
    }

    public static function finished(string $id, PestStatus $status, float $duration): string
    {
        return self::line(RecordLine::finished($id, $status, $duration));
    }

    public static function outcome(string $id, PestStatus $status): string
    {
        return self::line(RecordLine::outcome($id, $status));
    }

    /** How many mutants Pest made, after an opening run of 1.5 seconds. */
    public static function made(int $count): string
    {
        return self::line(RecordLine::made($count, Seconds::of(1.5)));
    }

    public static function end(): string
    {
        return self::line(RecordLine::end());
    }

    private static function line(string $written): string
    {
        return rtrim($written, "\n");
    }
}
