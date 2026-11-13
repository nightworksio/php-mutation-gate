<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_flip;
use function array_key_exists;
use function array_keys;

use Pest\Mutate\MutationTest;

/**
 * Which tests executed each mutant's lines, as a run planned without a fresh
 * map's streams recorded them (ADR-0135): its covering tests, read from the
 * lines each test executed in a stream's parent, by the mutant's file and
 * its first and last line.
 *
 * @internal
 */
final readonly class RecordedCovering
{
    /**
     * @param  array<string, MutationTest> $mutants by Pest's id
     * @return array<string, list<string>>  each mutant's covering tests, by their ids
     */
    public static function of(string $directory, array $mutants): array
    {
        $executed = $directory === '' ? [] : self::executed($directory);
        $covering = [];

        foreach ($mutants as $id => $test) {
            $file = (string) $test->mutation->file->getRealPath();
            $lines = array_key_exists($file, $executed) ? $executed[$file] : [];
            $covering[$id] = self::covering($lines, $test->mutation->startLine, $test->mutation->endLine);
        }

        return $covering;
    }

    /**
     * The tests that executed any of these lines, from the first to the last, each once.
     *
     * @param  array<int, array<string, true>> $lines the tests that executed each line of a file
     * @return list<string>
     */
    private static function covering(array $lines, int $first, int $last): array
    {
        $tests = [];

        foreach ($lines as $line => $by) {
            $tests = $line >= $first && $line <= $last ? [...$tests, ...array_keys($by)] : $tests;
        }

        return array_keys(array_flip($tests));
    }

    /** @return array<string, array<int, array<string, true>>> the tests that executed each line, by file and line */
    private static function executed(string $directory): array
    {
        $executed = [];

        foreach (RecordedMap::texts($directory) as $text) {
            $line = RecordedLine::of($text);

            foreach ($line->covered && $line->test !== '' ? $line->places() : [] as [$source, $number]) {
                $executed[$source][$number][$line->test] = true;
            }
        }

        return $executed;
    }
}
