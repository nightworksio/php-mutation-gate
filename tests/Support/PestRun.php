<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_put_contents;
use function implode;

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rtrim;
use function sprintf;

/** What this package's Pest plugin leaves after a run: its results file, one JSON line per record. */
final readonly class PestRun
{
    /** @param list<string> $lines each line as the plugin writes it, or any text a test writes in its place */
    public static function write(string $results, array $lines): void
    {
        file_put_contents($results, sprintf("%s\n", implode("\n", $lines)));
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
        return self::line(RecordLine::killed(self::mutated($id), $test));
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
