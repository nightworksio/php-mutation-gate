<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_any;
use function array_diff_key;
use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function implode;
use function is_file;
use function is_string;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Runner\IniScan;

use function preg_match;
use function round;
use function sprintf;

/**
 * The tests of a run that read the coverage driver (ADR-0183): each test
 * that executes a file, or is declared in one, that starts, stops or reads
 * pcov or Xdebug's coverage, picks a driver through php-code-coverage, or
 * asks whether pcov or Xdebug is on; and each declared in a test file that
 * starts a run that collects coverage itself. A process that
 * runs any of them starts with pcov as PHP is configured, where the run turns
 * it off in its other processes, so each such test runs as it runs anywhere
 * else.
 *
 * @internal
 */
final class PcovTests
{
    /** The variable a command is started with where its tests read the driver, so it keeps pcov on. */
    public const string KEPT = 'MUTATION_GATE_PCOV_KEPT';
    /** What a file holds where its code reads the coverage driver, any one of these. */
    private const array READS = [
        'pcov\\\\{1,2}(?:start|stop|collect|clear|waiting|memory)\\b',
        'xdebug_(?:start|stop|get)_code_coverage\\b',
        'CodeCoverage\\\\{1,2}Driver\\\\{1,2}',
        '(?:ini_get|extension_loaded)\\(\\s*[\'"](?:pcov|xdebug)',
    ];

    /**
     * What a test file holds where its tests start a run that collects
     * coverage themselves, any one of these; a run the gate's own code
     * starts collects as PHP is configured (see IniScan).
     */
    private const array COLLECTS = ['--coverage-', '--path-coverage'];

    /** The file beside a run's results that lists them, for the gate's own processes after the run. */
    private const string LISTED = '%s.pcov-tests';

    private const string ON = '1';

    private static ?self $inProcess = null;

    /** @param array<string, true> $tests the tests that read it, by their ids */
    private function __construct(private readonly array $tests)
    {
    }

    /**
     * Those of the run in this process, found once from the tests that cover
     * each line of each file of its map.
     *
     * @param array<string, array<int, array<int, string>>> $coveredLines
     */
    public static function of(array $coveredLines): self
    {
        if (! self::$inProcess instanceof self) {
            self::$inProcess = self::found($coveredLines);
            $results = getenv(GateVariable::Results->value);

            if (is_string($results) && $results !== '') {
                file_put_contents(sprintf(self::LISTED, $results), implode("\n", array_keys(self::$inProcess->tests)));
            }
        }

        return self::$inProcess;
    }

    /**
     * What a process of the gate's started after a run that runs these tests
     * is started with, as the run listed beside its results: a mark that it
     * keeps pcov on where any of them reads the driver; nothing otherwise.
     *
     * @param  list<string>          $tests
     * @return array<string, string>
     */
    public static function keptFor(string $results, array $tests): array
    {
        $listed = sprintf(self::LISTED, $results);
        $read = is_file($listed) ? explode("\n", (string) file_get_contents($listed)) : [];
        $reading = array_fill_keys(array_filter($read, static fn(string $test): bool => $test !== ''), value: true);

        return new self($reading)->readBy($tests) ? [self::KEPT => self::ON] : [];
    }

    /** Whether a command is started with the mark that it keeps pcov on. */
    public static function keeps(Command $command): bool
    {
        $environment = $command->environment();

        return array_key_exists(self::KEPT, $environment) && $environment[self::KEPT] === self::ON;
    }

    /**
     * The tests that read it, of each file of a map, and of each test file
     * that declares a test of the map.
     *
     * @param array<string, array<int, array<int, string>>> $coveredLines
     */
    public static function found(array $coveredLines): self
    {
        $tests = [];
        $covering = [];

        foreach ($coveredLines as $file => $lines) {
            $tested = self::testsOf($lines);
            $covering += $tested;
            $tests += self::holds($file, self::READS) ? $tested : [];
        }

        foreach (CoveringFiles::streamed(array_keys($covering)) as $file => $unit) {
            $reads = self::holds($file, [...self::READS, ...self::COLLECTS]);
            $tests += $reads ? array_fill_keys($unit['tests'], value: true) : [];
        }

        return new self($tests);
    }

    /**
     * How many of a pass's processes stream the units whose tests read the
     * driver: their share of the map's time, at least one where there are
     * any, and every one where no other unit is left; none where there are
     * none.
     *
     * @param array<string, array{files: list<string>, tests: list<string>}> $reading
     * @param array<string, array{files: list<string>, tests: list<string>}> $others
     * @param array<string, float>                                           $times
     */
    public static function processesFor(array $reading, array $others, array $times, int $processes): int
    {
        $read = self::secondsOf($reading, $times);
        $all = $read + self::secondsOf($others, $times);
        $share = $all > 0.0 ? (int) round($processes * $read / $all) : 1;

        return match (true) {
            $reading === [] => 0,
            $others === [] => max(1, $processes),
            default => min(max(1, $processes - 1), max(1, $share)),
        };
    }

    /**
     * Whether any of these tests reads the coverage driver.
     *
     * @param list<string> $tests
     */
    public function readBy(array $tests): bool
    {
        return array_any($tests, fn(string $test): bool => array_key_exists($test, $this->tests));
    }

    /**
     * What a process of a mutation run that runs these tests is started with:
     * pcov as PHP is configured where any of them reads the driver; pcov off
     * where the run turns it off otherwise.
     *
     * @param  list<string>                $tests
     * @return array<string, string|false>
     */
    public function variablesFor(array $tests): array
    {
        return $this->readBy($tests) ? self::kept() : self::off();
    }

    /**
     * What a process a mutation run starts is started with where the run
     * turns pcov off: the directory of the ini file that turns it off
     * scanned after what it scans; nothing otherwise.
     *
     * @return array<string, string>
     */
    public static function off(): array
    {
        $directory = getenv(GateVariable::PcovOff->value);

        return is_string($directory) && $directory !== '' ? IniScan::adding($directory, getenv()) : [];
    }

    /**
     * What a process that keeps pcov on is started with: pcov as PHP is
     * configured, and the mark that says so.
     *
     * @return array<string, string|false>
     */
    public static function kept(): array
    {
        return [...IniScan::restored(getenv()), self::KEPT => self::ON];
    }

    /**
     * These units of test files cut in two: those that hold a test that reads
     * the driver, and the others.
     *
     * @param  array<string, array{files: list<string>, tests: list<string>}> $units
     * @return array{
     *     array<string, array{files: list<string>, tests: list<string>}>,
     *     array<string, array{files: list<string>, tests: list<string>}>
     * }
     */
    public function split(array $units): array
    {
        $reading = array_filter($units, fn(array $unit): bool => $this->readBy($unit['tests']));

        return [$reading, array_diff_key($units, $reading)];
    }

    /**
     * Whether a file holds any of these.
     *
     * @param list<string> $patterns
     */
    private static function holds(string $file, array $patterns): bool
    {
        $pattern = sprintf('~%s~', implode('|', $patterns));

        return is_file($file) && preg_match($pattern, (string) file_get_contents($file)) === 1;
    }

    /**
     * The tests that cover any of a file's lines, by their ids.
     *
     * @param  array<int, array<int, string>> $lines
     * @return array<string, true>
     */
    private static function testsOf(array $lines): array
    {
        $tests = [];

        foreach ($lines as $tested) {
            $tests += array_fill_keys($tested, value: true);
        }

        return $tests;
    }

    /**
     * The map's time of every test of these units.
     *
     * @param array<string, array{files: list<string>, tests: list<string>}> $units
     * @param array<string, float>                                           $times
     */
    private static function secondsOf(array $units, array $times): float
    {
        $seconds = 0.0;

        foreach ($units as $unit) {
            foreach ($unit['tests'] as $test) {
                $seconds += array_key_exists($test, $times) ? $times[$test] : 0.0;
            }
        }

        return $seconds;
    }
}
