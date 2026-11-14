<?php

declare(strict_types=1);

// The half of `tests and coverage` that takes the judging suites from a full
// early run's streams (ADR-0135 decision 8), as scripts/coverage.sh runs it.
//
//   streamed-suite.php stands-in <suite dir>
//     Prints the suites the rest of the run leaves out, Guards and the
//     streamed ones, comma-separated; exits 1 where the streams cannot stand
//     in for them.
//
//   streamed-suite.php merge <suite dir> <rest coverage.php> <into> <clover> <junit>
//     Merges the lines each streamed test executed into the report of the
//     rest's run, through php-code-coverage's own filters, writes the merged
//     report to <into> and its Clover report to <clover>, adds the streamed
//     tests to <junit> as the testsuite `streams`, and exits 1 where a line
//     of the sources G7 holds is left uncovered (scripts/covered-sources.php).

use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\File\Path;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Test\TestStatus;
use SebastianBergmann\CodeCoverage\Util\PathReducer;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/covered-sources.php';

const ROOT = __DIR__ . '/..';
const LEFT_OUT = 'Guards';
const SUITE_NAME = '/^[A-Za-z0-9 _.-]+$/';
const SEED = 'streams';

function fail(string $why): never
{
    fwrite(STDERR, $why . "\n");

    exit(1);
}

function suiteIn(string $directory): StreamedSuite
{
    $suite = StreamedSuite::decoded((string) @file_get_contents($directory . '/suite.json'));

    return $suite instanceof StreamedSuite ? $suite : fail("No streamed suite can be read in {$directory}.");
}

/** The streamed tests' coverage, through the filters PHPUnit's own run applies. */
function streamed(string $directory, StreamedSuite $suite): CodeCoverage
{
    $root = (string) realpath(ROOT);
    $sources = coveredSources($root);
    $filter = new Filter();
    $filter->includeFiles($sources);
    $coverage = new CodeCoverage(new Selector()->forLineCoverage($filter), $filter);
    $coverage->excludeUncoveredFiles();
    $included = array_flip($sources);
    $seed = [];

    foreach ($suite->reported() as $file => $lines) {
        if (isset($included["{$root}/{$file}"])) {
            $seed["{$root}/{$file}"] = array_fill_keys($lines, -1);
        }
    }

    $coverage->append(RawCodeCoverageData::fromLineCoverage($seed), SEED, append: false);
    $bytes = (string) @file_get_contents(CoverageMapFile::in(Path::of($directory))->value());
    $map = CoverageMapFile::decode($bytes, Handoff::limits());
    $map instanceof CoverageMap ?: fail("The map the streams recorded cannot be read in {$directory}.");
    $executed = [];

    foreach ($map->lines() as $line) {
        foreach ($line as $test) {
            $executed[$test->value()]["{$root}/{$line->file()->value()}"][$line->line()] = 1;
        }
    }

    $seconds = $suite->secondsByTest();

    foreach ($executed as $test => $lines) {
        $raw = RawCodeCoverageData::fromLineCoverage($lines);
        $coverage->append($raw, $test, status: TestStatus::Success, time: $seconds[$test] ?? 0.0);
    }

    return $coverage;
}

/** @return array{buildInformation: array<mixed>, basePath: string, codeCoverage: ProcessedCodeCoverageData, testResults: array<string, array{size: string, status: string, time: float}>} */
function rest(string $file): array
{
    $rest = require $file;

    return is_array($rest) && ($rest['codeCoverage'] ?? null) instanceof ProcessedCodeCoverageData && is_string($rest['basePath'] ?? null)
        ? $rest
        : fail("The rest's report {$file} cannot be read.");
}

function junit(string $file, StreamedSuite $suite): void
{
    $document = new DOMDocument();
    $document->load($file) ?: fail("The JUnit report {$file} cannot be read.");
    $streams = $document->createElement('testsuite');
    $streams->setAttribute('name', SEED);
    $streams->setAttribute('tests', (string) $suite->count());
    $streams->setAttribute('failures', '0');
    $streams->setAttribute('errors', '0');

    foreach ($suite->secondsByTest() as $test => $seconds) {
        [$class, $name] = str_contains($test, '::') ? explode('::', $test, 2) : [$test, $test];
        $case = $document->createElement('testcase');
        $case->setAttribute('name', $name);
        $case->setAttribute('class', $class);
        $case->setAttribute('classname', $class);
        $case->setAttribute('time', sprintf('%.6F', $seconds));
        $streams->appendChild($case);
    }

    $document->documentElement?->appendChild($streams);
    $document->save($file) ?: fail("The JUnit report {$file} cannot be written.");
}

function merge(string $directory, string $restFile, string $into, string $clover, string $junit): void
{
    $suite = suiteIn($directory);
    $suite->standsIn() ?: fail('The streams cannot stand in for their suites.');
    $rest = rest($restFile);
    $data = $rest['codeCoverage'];
    $base = rtrim($rest['basePath'], '/');

    foreach ($rest['basePath'] === '' ? [] : $data->coveredFiles() as $file) {
        $data->renameFile($file, "{$base}/{$file}");
    }

    $streamed = streamed($directory, $suite);
    $data->merge($streamed->getData(raw: true));
    withoutExemptions($data, (string) realpath(ROOT));
    $merged = [
        'buildInformation' => $rest['buildInformation'],
        'basePath' => new PathReducer()->reduce($data),
        'codeCoverage' => $data,
        'testResults' => $rest['testResults'] + $streamed->getTests(),
    ];
    $header = strtok((string) file_get_contents($restFile), "\n");
    file_put_contents($into, $header . "\nreturn \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n"
        . serialize($merged) . "\nEND_OF_COVERAGE_SERIALIZATION\n);");
    $facade = Facade::fromSerializedData($merged);
    $facade->renderClover($clover);
    junit($junit, $suite);
    $summary = $facade->summary();

    if ($summary->numberOfExecutedLines() < $summary->numberOfExecutableLines()) {
        fail(sprintf(
            'The merged run covers %d of %d lines, short of every line.',
            $summary->numberOfExecutedLines(),
            $summary->numberOfExecutableLines(),
        ));
    }
}

function standsIn(string $directory): void
{
    $suite = suiteIn($directory);
    $suite->standsIn() ?: fail('The streams cannot stand in for their suites: one stopped short, a test failed, raised an issue or ran in a process of its own.');

    foreach ($suite->suites() as $name) {
        preg_match(SUITE_NAME, $name) === 1 ?: fail("The streamed suite name {$name} is not one phpunit.xml could declare.");
    }

    echo implode(',', [LEFT_OUT, ...$suite->suites()]), "\n";
}

match ($argv[1] ?? '') {
    'stands-in' => standsIn($argv[2] ?? fail('Name the suite directory.')),
    'merge' => merge(
        $argv[2] ?? fail('Name the suite directory.'),
        $argv[3] ?? fail("Name the rest's report."),
        $argv[4] ?? fail('Name the merged report.'),
        $argv[5] ?? fail('Name the Clover report.'),
        $argv[6] ?? fail('Name the JUnit report.'),
    ),
    default => fail('Usage: streamed-suite.php stands-in <dir> | merge <dir> <rest> <into> <clover> <junit>'),
};
