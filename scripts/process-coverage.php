<?php

declare(strict_types=1);

// Every line of src covered (G7), but the files scripts/coverage-exemptions.php
// names, across the runs that execute it: the suite's own PHP reports, and the
// lines each PHP process a test started recorded through
// tests/Support/ProcessCoverage/prepend.php, the processes a split stream
// forks among them.
//
//   process-coverage.php ini <dir>
//     Writes <dir>/ini/process-coverage.ini and the script it prepends, which
//     have every PHP process that scans <dir>/ini, outside the root, record
//     its lines into <dir>/lines.
//
//   process-coverage.php tested <report> <lines dir>
//     Merges into the PHP report <report> the lines each process recorded
//     that a test of the report started, as that test's own, so the map read
//     from it names the test that runs them; every file of the sources, the
//     ones G7 leaves off its floor among them.
//
//   process-coverage.php joined <into> <report>...
//     Joins PHP reports with their tests into the report <into>, under the
//     first report's header, so the map read from it names the tests of each;
//     a test a later report holds stands in for the same test of an earlier.
//
//   process-coverage.php collect <into> <report|lines dir>...
//     Merges each PHP report and each directory of recorded lines into the
//     report <into>, for a job that holds no floor itself.
//
//   process-coverage.php merge <into> <clover> <report|lines dir>...
//     Merges as collect does, writes its Clover report <clover>, names each
//     file of src with a line left uncovered, and exits 1 where one is.

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Node\File;
use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Util\PathReducer;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/covered-sources.php';

const ROOT = __DIR__ . '/..';
const PREPEND = 'tests/Support/ProcessCoverage/prepend.php';
const INI = <<<'INI'
    pcov.enabled=1
    pcov.directory=%s
    pcov.exclude="~/vendor/~"
    auto_prepend_file=%s

    INI;
const PREPENDED = "<?php\n\n(require %s)(%s, %s);\n";

function fail(string $why): never
{
    fwrite(STDERR, $why . "\n");

    exit(1);
}

function ini(string $directory): void
{
    foreach (["{$directory}/ini", "{$directory}/lines"] as $made) {
        if (!is_dir($made) && !mkdir($made, 0o755, true)) {
            fail("{$made} cannot be made.");
        }
    }

    $root = (string) realpath(ROOT);
    $lines = (string) realpath("{$directory}/lines");
    $prepended = sprintf('%s/prepend.php', (string) realpath("{$directory}/ini"));
    $script = sprintf(PREPENDED, var_export("{$root}/" . PREPEND, true), var_export($lines, true), var_export($root, true));
    file_put_contents($prepended, $script) ?: fail("{$prepended} cannot be written.");
    file_put_contents(
        "{$directory}/ini/process-coverage.ini",
        sprintf(INI, $root, $prepended),
    ) ?: fail("{$directory}/ini/process-coverage.ini cannot be written.");
}

/** A PHP report's data, its paths absolute. */
function report(string $file): ProcessedCodeCoverageData
{
    $report = require $file;
    $report = $report instanceof CodeCoverage
        ? ['codeCoverage' => $report->getData(), 'basePath' => '']
        : $report;
    $report = is_array($report) ? $report : [];
    $data = $report['codeCoverage'] ?? null;
    $base = $report['basePath'] ?? null;
    $data instanceof ProcessedCodeCoverageData && is_string($base) ?: fail("The PHP report {$file} cannot be read.");

    foreach ($base === '' ? [] : $data->coveredFiles() as $covered) {
        $data->renameFile($covered, rtrim($base, '/') . '/' . $covered);
    }

    return $data;
}

/**
 * One process's recording: the test that started it, where a test did, and the lines it ran.
 *
 * @return array{string|null, array<string, array<int, int>>}
 */
function recording(string $file): array
{
    $recorded = json_decode((string) file_get_contents($file), true);
    is_array($recorded) && array_key_exists('lines', $recorded) && is_array($recorded['lines'])
        ?: fail("The recorded lines {$file} cannot be read.");
    $test = array_key_exists('test', $recorded) && is_string($recorded['test']) ? $recorded['test'] : null;

    return [$test, $recorded['lines']];
}

/**
 * The lines the processes recorded into a directory, through the filters
 * PHPUnit's own run applies: each process's as the test that started it
 * ran them, or as its own where no test did; where these tests are named,
 * only those one of them started.
 *
 * @param list<string>|null $among
 */
function recorded(string $directory, Filter $filter, ?array $among = null): ProcessedCodeCoverageData
{
    $coverage = new CodeCoverage(new Selector()->forLineCoverage($filter), $filter);
    $coverage->excludeUncoveredFiles();

    foreach (glob("{$directory}/*.json") ?: [] as $file) {
        [$test, $lines] = recording($file);

        if ($among === null || in_array($test, $among, strict: true)) {
            $coverage->append(RawCodeCoverageData::fromLineCoverage($lines), $test ?? basename($file, '.json'));
        }
    }

    return $coverage->getData(raw: true);
}

/** @return array<string, mixed> a PHP report as it was written */
function unserialized(string $file): array
{
    $read = require $file;

    return is_array($read) ? $read : fail("The PHP report {$file} cannot be read.");
}

/** A PHP report's first line, which names the format it is serialized in. */
function firstLine(string $file): string
{
    return (string) strtok((string) file_get_contents($file), "\n");
}

function tested(string $report, string $lines): void
{
    $read = unserialized($report);
    $filter = new Filter();
    $filter->includeFiles(sourceFiles((string) realpath(ROOT)));
    $data = report($report);
    $data->merge(recorded($lines, $filter, array_values($data->testIds())));

    written($report, [...$read, 'basePath' => new PathReducer()->reduce($data), 'codeCoverage' => $data], firstLine($report));
}

/** @param non-empty-list<string> $reports */
function joined(string $into, array $reports): void
{
    $data = report($reports[0]);
    $results = [];

    foreach ($reports as $at => $report) {
        $tests = unserialized($report)['testResults'] ?? [];
        is_array($tests) ?: fail("The PHP report {$report} holds no test results.");
        $results = [...$results, ...$tests];
        $at === 0 ?: $data->merge(report($report));
    }

    written(
        $into,
        [...unserialized($reports[0]), 'basePath' => new PathReducer()->reduce($data), 'codeCoverage' => $data, 'testResults' => $results],
        firstLine($reports[0]),
    );
}

/** @param list<string> $parts */
function collected(array $parts): CodeCoverage
{
    $root = (string) realpath(ROOT);
    $filter = new Filter();
    $filter->includeFiles(coveredSources($root));
    $coverage = new CodeCoverage(new Selector()->forLineCoverage($filter), $filter);
    $data = $coverage->getData(raw: true);

    foreach ($parts as $part) {
        $data->merge(is_dir($part) ? recorded($part, $filter) : report($part));
    }

    withoutExemptions($data, $root);
    $coverage->setData($data);

    return $coverage;
}

/** @return array<string, mixed> the report as PHPUnit's PHP report serializes it */
function serialized(CodeCoverage $coverage): array
{
    $data = $coverage->getData(raw: true);

    return [
        'buildInformation' => [],
        'basePath' => new PathReducer()->reduce($data),
        'codeCoverage' => $data,
        'testResults' => [],
    ];
}

/**
 * Writes a report as PHPUnit's PHP report does, under this first line.
 *
 * @param array<string, mixed> $report
 */
function written(string $into, array $report, string $header = '<?php'): void
{
    if (!is_dir(dirname($into)) && !mkdir(dirname($into), 0o755, true)) {
        fail(dirname($into) . ' cannot be made.');
    }
    file_put_contents($into, $header . "\nreturn \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n"
        . serialize($report) . "\nEND_OF_COVERAGE_SERIALIZATION\n);") ?: fail("{$into} cannot be written.");
}

/** @param list<string> $parts */
function collect(string $into, array $parts): void
{
    written($into, serialized(collected($parts)));
}

/** @param list<string> $parts */
function merge(string $into, string $clover, array $parts): void
{
    $coverage = collected($parts);
    $report = serialized($coverage);
    written($into, $report);
    Facade::fromSerializedData($report)->renderClover($clover);
    $uncovered = [];

    foreach ($coverage->getReport() as $node) {
        if ($node instanceof File && $node->numberOfExecutedLines() < $node->numberOfExecutableLines()) {
            $left = array_keys(array_filter($node->lineCoverageData(), static fn(?array $tests): bool => $tests === []));
            $uncovered[] = sprintf(
                '%s: %s (%.1f%%)',
                $node->pathAsString(),
                implode(', ', $left),
                100 * $node->numberOfExecutedLines() / $node->numberOfExecutableLines(),
            );
        }
    }

    if ($uncovered !== []) {
        fail(sprintf("Every line of src is covered but these:\n%s", implode("\n", $uncovered)));
    }
}

match ($argv[1] ?? '') {
    'ini' => ini($argv[2] ?? fail('Name the directory.')),
    'tested' => tested(
        $argv[2] ?? fail('Name the PHP report.'),
        $argv[3] ?? fail('Name the directory of recorded lines.'),
    ),
    'joined' => joined(
        $argv[2] ?? fail('Name the joined report.'),
        array_slice($argv, 3) ?: fail('Name a PHP report.'),
    ),
    'collect' => collect(
        $argv[2] ?? fail('Name the collected report.'),
        array_slice($argv, 3) ?: fail('Name a report or a directory of recorded lines.'),
    ),
    'merge' => merge(
        $argv[2] ?? fail('Name the merged report.'),
        $argv[3] ?? fail('Name the Clover report.'),
        array_slice($argv, 4) ?: fail('Name a report or a directory of recorded lines.'),
    ),
    default => fail('Usage: process-coverage.php ini <dir> | tested <report> <lines dir> | joined <into> <report>... | collect <into> <report|lines dir>... | merge <into> <clover> <report|lines dir>...'),
};
