<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function array_map;
use function basename;
use function count;
use function dirname;
use function explode;
use function file_put_contents;
use function htmlspecialchars;
use function implode;
use function is_dir;
use function json_encode;
use function mb_strlen;
use function mkdir;
use function sprintf;
use function str_repeat;

/**
 * What PHPUnit and Infection leave after a run, as the Infection adapter reads
 * them: a coverage directory in the layout `--coverage` reads, and
 * Infection's JSON and text logs.
 */
final readonly class InfectionRun
{
    /**
     * A coverage directory: PHPUnit's XML coverage of files under a source
     * directory, and its JUnit log.
     *
     * @param array<string, array<int, list<string>>> $files   each file under the source, by its path there, with the tests covering each line
     * @param array<string, float>                    $classes each test class's seconds
     * @param array<string, float>                    $tests   each test's seconds, by its id
     */
    public static function coverage(string $directory, string $source, array $files, array $classes, array $tests): void
    {
        $entries = [];

        foreach ($files as $path => $lines) {
            $entries[] = sprintf('<file name="%s" href="%s.xml"/>', basename($path), $path);
            self::write(sprintf('%s/coverage-xml/%s.xml', $directory, $path), self::report($path, $lines));
        }

        self::write(sprintf('%s/coverage-xml/index.xml', $directory), sprintf(
            '<?xml version="1.0"?><phpunit xmlns="https://schema.phpunit.de/coverage/1.0"><project source="%s"><directory name="/">%s</directory></project></phpunit>',
            $source,
            implode('', $entries),
        ));
        self::write(sprintf('%s/junit.xml', $directory), self::junit($classes, $tests));
    }

    /**
     * Infection's JSON log, with each list's entries and the counts in `stats`,
     * each count the size of its list unless given.
     *
     * @param array<string, list<array<string, mixed>>> $lists
     * @param array<string, int>                        $counts
     */
    public static function log(string $file, array $lists, array $counts = []): void
    {
        $names = [
            'killed' => 'killedCount',
            'killedByStaticAnalysis' => 'killedByStaticAnalysisCount',
            'escaped' => 'escapedCount',
            'errored' => 'errorCount',
            'syntaxErrors' => 'syntaxErrorCount',
            'timeouted' => 'timeOutCount',
            'uncovered' => 'notCoveredCount',
            'ignored' => 'ignoredCount',
        ];
        $stats = ['skippedCount' => 0];
        $data = [];
        $total = 0;

        foreach ($names as $list => $count) {
            $data[$list] = array_key_exists($list, $lists) ? $lists[$list] : [];
            $stats[$count] = array_key_exists($count, $counts) ? $counts[$count] : count($data[$list]);
            $total += $stats[$count];
        }

        $stats = [...$stats, ...$counts];
        $stats['totalMutantsCount'] = array_key_exists('totalMutantsCount', $counts)
            ? $counts['totalMutantsCount']
            : $total + $stats['skippedCount'];

        self::write($file, (string) json_encode(['stats' => $stats, ...$data]));
    }

    /** @return array<string, mixed> one mutant as the JSON log lists it */
    public static function entry(string $mutator, string $file, int $line, string $removed, string $added): array
    {
        return [
            'mutator' => [
                'mutatorName' => $mutator,
                'originalSourceCode' => '<?php',
                'mutatedSourceCode' => '<?php',
                'originalFilePath' => $file,
                'originalStartLine' => $line,
            ],
            'diff' => self::diff($removed, $added),
            'processOutput' => 'OK (1 test, 1 assertion)',
        ];
    }

    /**
     * Infection's text log: each heading with the mutants under it.
     *
     * @param array<string, list<array{string, int, string, string, string, string}>> $sections each heading's mutants: file, line, mutator, native id, removed and added
     */
    public static function text(string $file, array $sections): void
    {
        $lines = ['Note: Pass `--log-verbosity=all` to log information about killed and errored mutants.', ''];

        foreach ($sections as $heading => $mutants) {
            $title = sprintf('%s mutants:', $heading);
            $lines = [...$lines, $title, str_repeat('=', mb_strlen($title)), ''];

            foreach ($mutants as $index => [$path, $line, $mutator, $id, $removed, $added]) {
                $lines = [...$lines, sprintf('%d) %s:%d    [M] %s [ID] %s', $index + 1, $path, $line, $mutator, $id), '', self::diff($removed, $added), '', ''];
            }
        }

        self::write($file, implode("\n", $lines));
    }

    public static function diff(string $removed, string $added): string
    {
        return sprintf("@@ @@\n     {\n-        %s\n+        %s\n     }", $removed, $added);
    }

    /** @param array<int, list<string>> $lines */
    private static function report(string $path, array $lines): string
    {
        $covered = '';

        foreach ($lines as $number => $tests) {
            $by = implode('', array_map(static fn(string $test): string => sprintf('<covered by="%s" count="1"/>', $test), $tests));
            $covered .= sprintf('<line nr="%d">%s</line>', $number, $by);
        }

        $directory = dirname($path);

        return sprintf(
            '<?xml version="1.0"?><phpunit xmlns="https://schema.phpunit.de/coverage/1.0"><file name="%s" path="/%s"><coverage>%s</coverage></file></phpunit>',
            basename($path),
            $directory === '.' ? '' : $directory,
            $covered,
        );
    }

    /**
     * @param array<string, float> $classes
     * @param array<string, float> $tests
     */
    private static function junit(array $classes, array $tests): string
    {
        $suites = '';

        foreach ($classes as $class => $seconds) {
            $cases = '';

            foreach ($tests as $test => $time) {
                [$owner, $name] = explode('::', $test, 2);
                $cases .= $owner === $class
                    ? sprintf('<testcase name="%s" class="%s" time="%F"/>', htmlspecialchars($name), $owner, $time)
                    : '';
            }

            $suites .= sprintf('<testsuite name="%s" file="tests/%s.php" time="%F">%s</testsuite>', $class, $class, $seconds, $cases);
        }

        return sprintf('<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="phpunit.xml" time="1.0">%s</testsuite></testsuites>', $suites);
    }

    private static function write(string $file, string $contents): void
    {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), recursive: true);
        }

        file_put_contents($file, $contents);
    }
}
