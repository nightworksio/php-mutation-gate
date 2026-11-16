<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;
use Symfony\Component\Process\Process;

// The gate's own map: each PHP process a test of the suite starts records the
// lines it runs as that test's own (tests/Support/ProcessCoverage), and
// scripts/process-coverage.php tested merges them into the PHP report the map
// is read from, so a line only such a process runs is judged by that test;
// joined joins the suite's report and the runner contract's into that report.

/** The test whose fresh process runs a stream's code the test's own process does not. */
const STARTING_TEST = 'tests/Unit/Adapter/Pest/Split/StreamTest.php';

const STARTING_FILTER = 'writes how the parent came out of each test it ran';

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * This command, run at the root, in these variables besides; what it said where it failed.
 *
 * @param list<string>          $command
 * @param array<string, string> $variables
 */
function processLinesRun(array $command, array $variables = []): string
{
    $process = new Process($command, Tree::root(), [...Command::unset(), ...$variables], timeout: 600);
    $process->run();

    return $process->isSuccessful() ? '' : sprintf("%s\n%s%s", $process->getCommandLine(), $process->getOutput(), $process->getErrorOutput());
}

/**
 * Each line of src a test ran, as its file's path on disk and its line, as a PHP report reads.
 *
 * @return list<string>
 */
function processLinesRan(string $report): array
{
    $read = require $report;
    $data = is_array($read) ? $read['codeCoverage'] : null;
    $base = is_array($read) && is_string($read['basePath']) ? rtrim($read['basePath'], '/') : '';
    $ran = [];

    foreach ($data instanceof ProcessedCodeCoverageData ? $data->lineCoverage() : [] as $file => $lines) {
        $executed = array_keys(array_filter($lines, static fn(?array $tests): bool => $tests !== null && $tests !== []));
        $ran = [...$ran, ...array_map(static fn(int $line): string => sprintf('%s/%s:%d', $base, $file, $line), $executed)];
    }

    return $ran;
}

/**
 * A PHP report as PHPUnit writes it, in which each test ran these lines and took these seconds.
 *
 * @param non-empty-string                                                          $into
 * @param array<non-empty-string, array{float, array<non-empty-string, list<int<1, max>>>}> $tests
 */
function processLinesReport(string $into, array $tests): void
{
    $data = new ProcessedCodeCoverageData();

    foreach ($tests as $test => [, $lines]) {
        $ran = RawCodeCoverageData::fromLineCoverage(array_map(static fn(array $at): array => array_fill_keys($at, 1), $lines));
        $data->initializeUnseenData($ran);
        $data->markCodeAsExecutedByTestCase($test, $ran);
    }

    $coverage = new CodeCoverage(new class extends Driver {
        public function name(): string
        {
            return 'none';
        }

        public function version(): string
        {
            return '0';
        }

        public function start(): void
        {
        }

        public function stop(): RawCodeCoverageData
        {
            return RawCodeCoverageData::fromLineCoverage([]);
        }
    }, new Filter());
    $coverage->setData($data);
    $coverage->setTests(array_map(static fn(array $test): array => ['size' => 'small', 'status' => 'success', 'time' => $test[0]], $tests));
    new Serializer()->serialize($into, $coverage);
}

it('maps the tests of every report it joins, a later report standing in for an earlier one\'s test', function (): void {
    $scratch = Scratch::directory();
    [$suite, $runner, $joined] = array_map(static fn(string $name): string => sprintf('%s/%s.php', $scratch, $name), ['suite', 'runner', 'joined']);
    [$first, $second] = [sprintf('%s/src/First.php', $scratch), sprintf('%s/src/Second.php', $scratch)];
    processLinesReport($suite, ['Unit::own' => [1.5, [$first => [3, 4]]], 'Contract::skipped' => [0.01, [$first => [9]]]]);
    processLinesReport($runner, ['Contract::skipped' => [2.5, [$first => [5], $second => [7]]]]);

    $failure = processLinesRun([PHP_BINARY, 'scripts/process-coverage.php', 'joined', $joined, $suite, $runner]);
    $map = CoverageFile::at($joined);
    $covering = static fn(string $file, int $line): array => $map instanceof CoverageFile
        ? array_map(static fn(TestId $test): string => $test->value(), [...$map->testsCovering(DiskPath::of($file), Line::of($line), Line::of($line))])
        : [];

    expect($failure)->toBe('')
        ->and($map instanceof CannotJudge ? $map->why() : '')->toBe('')
        ->and($map instanceof CoverageFile ? array_map(static fn(TestId $test): string => $test->value(), [...$map->tests()]) : [])
        ->toBe(['Unit::own', 'Contract::skipped'])
        ->and($map instanceof CoverageFile ? $map->secondsOf(TestId::of('Contract::skipped')) : 0.0)->toBe(2.5)
        ->and($covering($first, 3))->toBe(['Unit::own'])
        ->and($covering($first, 5))->toBe(['Contract::skipped'])
        ->and($covering($second, 7))->toBe(['Contract::skipped']);
});

it('maps each line only a process a test started runs to that test', function (): void {
    $scratch = Scratch::directory();
    $processes = sprintf('%s/processes', $scratch);
    $report = sprintf('%s/coverage.php', $scratch);

    $failures = [
        processLinesRun([PHP_BINARY, 'scripts/process-coverage.php', 'ini', $processes]),
        processLinesRun(
            [
                PHP_BINARY, '-d', 'pcov.enabled=1', '-d', 'pcov.directory=.', '-d', 'pcov.exclude=~/(vendor|tests)/~',
                'vendor/bin/pest', STARTING_TEST, sprintf('--filter=%s', STARTING_FILTER), sprintf('--coverage-php=%s', $report),
            ],
            ['PHP_INI_SCAN_DIR' => sprintf(':%s/ini', $processes)],
        ),
    ];
    $own = processLinesRan($report);
    $failures[] = processLinesRun([PHP_BINARY, 'scripts/process-coverage.php', 'tested', $report, sprintf('%s/lines', $processes)]);
    $started = array_values(array_diff(processLinesRan($report), $own));
    $map = CoverageFile::at($report);
    $failures[] = $map instanceof CannotJudge ? $map->why() : '';
    $covering = $map instanceof CoverageFile
        ? static fn(string $file, Line $at): TestIds => $map->testsCovering(DiskPath::of($file), $at, $at)
        : static fn(): TestIds => TestIds::of();
    $judging = [];

    foreach ($started as $site) {
        [$file, $line] = explode(':', $site);
        $judging = [...$judging, ...array_map(static fn(TestId $test): string => $test->value(), [...$covering($file, Line::of((int) $line))])];
    }

    expect(array_filter($failures, static fn(string $said): bool => $said !== ''))->toBe([])
        ->and($started)->not->toBe([], 'the started process ran no line of src the test\'s own process did not')
        ->and(array_values(array_unique($judging)))
        ->toBe([sprintf('P\\Tests\\Unit\\Adapter\\Pest\\Split\\StreamTest::__pest_evaluable_it_%s', str_replace(' ', '_', STARTING_FILTER))]);
});
