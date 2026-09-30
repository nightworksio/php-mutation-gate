<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function file_put_contents;
use function implode;
use function is_string;
use function json_encode;
use function sprintf;

/** What this package's Pest plugin leaves after a run: its results file, one JSON line per record. */
final readonly class PestRun
{
    /** @param list<array<string, mixed>|string> $records a string is written as it is */
    public static function write(string $results, array $records): void
    {
        $lines = array_map(
            static fn(mixed $record): string => is_string($record)
                ? $record
                : (string) json_encode($record, JSON_PRESERVE_ZERO_FRACTION),
            $records,
        );

        file_put_contents($results, sprintf("%s\n", implode("\n", $lines)));
    }

    /** @return array<string, mixed> a mutant as the plugin plans it */
    public static function planned(
        string $id,
        string $file,
        int $line,
        string $mutator,
        string $removed,
        string $added,
    ): array {
        return [
            'event' => 'planned',
            'id' => $id,
            'file' => $file,
            'start' => $line,
            'end' => $line,
            'mutator' => $mutator,
            'diff' => sprintf("\n  <fg=red>-        %s</>\n  <fg=green>+        %s</>\n", $removed, $added),
            'mutated' => self::mutated($id),
        ];
    }

    /** @return array<string, mixed> a test that failed in the own process of the mutant with this native id */
    public static function killed(string $id, string $test): array
    {
        return ['event' => 'killed', 'mutated' => self::mutated($id), 'test' => $test];
    }

    /** Where Pest keeps the mutated copy of the mutant with this native id. */
    public static function mutated(string $id): string
    {
        return sprintf('/tmp/mutations/%s', $id);
    }

    /** @return array<string, mixed> */
    public static function finished(string $id, string $status, float $duration): array
    {
        return ['event' => 'finished', 'id' => $id, 'status' => $status, 'duration' => $duration];
    }

    /** @return array<string, mixed> */
    public static function outcome(string $id, string $status): array
    {
        return ['event' => 'outcome', 'id' => $id, 'status' => $status];
    }

    /** @return array<string, mixed> how many mutants Pest made, after an opening run of 1.5 seconds */
    public static function made(int $count): array
    {
        return ['event' => 'made', 'count' => $count, 'opening' => 1.5];
    }

    /** @return array<string, mixed> */
    public static function end(): array
    {
        return ['event' => 'end'];
    }
}
