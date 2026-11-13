<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_flip;
use function array_keys;
use function file;
use function glob;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;

use Pest\Mutate\MutationTest;

use function sprintf;

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
        $executed = self::executed($directory);
        $covering = [];

        foreach ($mutants as $id => $test) {
            $file = (string) $test->mutation->file->getRealPath();
            $tests = [];

            foreach ($executed[$file] ?? [] as $line => $by) {
                if ($line >= $test->mutation->startLine && $line <= $test->mutation->endLine) {
                    $tests = [...$tests, ...array_keys($by)];
                }
            }

            $covering[$id] = array_keys(array_flip($tests));
        }

        return $covering;
    }

    /** @return array<string, array<int, array<string, true>>> the tests that executed each line, by file and line */
    private static function executed(string $directory): array
    {
        $executed = [];

        foreach ($directory === '' ? [] : (glob(sprintf(RecordedMap::RECORDED, $directory)) ?: []) as $file) {
            foreach (file($file) ?: [] as $text) {
                $line = json_decode($text, associative: true);

                if (! is_array($line) || ($line[StreamLine::KIND] ?? '') !== StreamLine::COVERED) {
                    continue;
                }

                $test = $line['t'] ?? null;

                foreach (is_string($test) && is_array($line['l'] ?? null) ? $line['l'] : [] as $source => $numbers) {
                    foreach (is_string($source) && is_array($numbers) ? $numbers : [] as $number) {
                        if (is_int($number)) {
                            $executed[$source][$number][$test] = true;
                        }
                    }
                }
            }
        }

        return $executed;
    }
}
