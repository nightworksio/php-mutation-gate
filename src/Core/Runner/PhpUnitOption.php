<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Suites;

use function sprintf;
use function str_contains;
use function str_ends_with;
use function version_compare;

/**
 * An option of PHPUnit's command line that the PHPUnit runner's adapter
 * writes, or that more than one runner's adapter writes.
 */
enum PhpUnitOption: string
{
    /** Loads an extension, the class named next. */
    case Extension = '--extension';

    /** Runs the tests whose ids are listed, a line each, in the file named after `=`. */
    case TestIdFilterFile = '--test-id-filter-file';

    /** Runs the tests in the files listed, a line each, in the file named after `=`. */
    case TestFilesFile = '--test-files-file';

    /** Runs the tests of the group named next. */
    case Group = '--group';

    /** Runs the tests of the `<testsuite>` named after `=` alone, beside any group or filter. */
    case TestSuite = '--testsuite';

    /** Runs the tests whose names match the pattern next. */
    case Filter = '--filter';

    /** Stops the run at the first test that errors. */
    case StopOnError = '--stop-on-error';

    /** Stops the run at the first test that fails. */
    case StopOnFailure = '--stop-on-failure';

    /** Collects no coverage, whatever the project's config asks. */
    case NoCoverage = '--no-coverage';

    /** Leaves out the logs the project's config writes. */
    case NoLogging = '--no-logging';

    /** Shows no progress, so what PHPUnit prints is what went wrong. */
    case NoProgress = '--no-progress';

    /** Leaves PHPUnit's result cache as it was, as a PHPUnit before the test run history names it. */
    case DoNotCacheResult = '--do-not-cache-result';

    /** Leaves PHPUnit's test run history as it was. */
    case DoNotRecordTestRunHistory = '--do-not-record-test-run-history';

    /** Passes a run that selects no test. */
    case DoNotFailOnEmptyTestSuite = '--do-not-fail-on-empty-test-suite';

    /** Writes the coverage map as PHP, to the file named after `=`. */
    case CoveragePhp = '--coverage-php';

    /** Writes a Clover report, every executable line with how many runs it had, to the file after `=`. */
    case CoverageClover = '--coverage-clover';

    /** Writes the suite's tests and each group's tests to a file as XML, and runs no test. */
    case ListTestsXml = '--list-tests-xml';

    /** Prints no colour, whatever the project's config asks, so what PHPUnit prints can be read back. */
    case NoColors = '--colors=never';

    /** Logs each test's outcome as JUnit, to the file named after `=`. */
    case LogJunit = '--log-junit';

    /** Logs every event of the run as text, each issue raised among them, to the file named after `=`. */
    case LogEventsText = '--log-events-text';

    /** How a line of a file PHPUnit reads a list from ends. */
    public const string LINE_END = "\n";

    /** The first PHPUnit that selects tests by their ids, with `--test-id-filter-file`. */
    public const string IDS_SINCE = '13.2.0';

    /** What PHPUnit drops before a line's end. */
    private const string CARRIAGE_RETURN = "\r";

    /** The first PHPUnit that names its result cache the test run history, and deprecates `--do-not-cache-result`. */
    private const string HISTORY_SINCE = '13.3.0';

    /**
     * The option that keeps a run to these suites' tests, as `tests.suites`
     * lists them (ADR-0002) or `--suite` names one (ADR-0025, decision 9);
     * none where the run runs every suite.
     *
     * @return list<string>
     */
    public static function inSuites(Suites $suites): array
    {
        return $suites->isAll() ? [] : [sprintf('%s=%s', self::TestSuite->value, $suites->joined())];
    }

    /**
     * The option that leaves the test run history as it was, as this PHPUnit
     * names it: `--do-not-cache-result` before 13.3, which has no other, and
     * `--do-not-record-test-run-history` from 13.3 on, on a branch, and where
     * the version is not known. PHPUnit 13.3 reports the older option as a
     * deprecation, which fails the run where the project's config fails on
     * PHPUnit's own deprecations.
     */
    public static function leavingHistoryOf(Version|NotGiven $phpunit): self
    {
        $beforeHistory = $phpunit instanceof Version
            && $phpunit->isRelease()
            && version_compare($phpunit->release(), self::HISTORY_SINCE, '<');

        return $beforeHistory ? self::DoNotCacheResult : self::DoNotRecordTestRunHistory;
    }

    /**
     * Whether PHPUnit reads an entry of a list file back as it is: it reads
     * the file a line at a time and drops a carriage return before a line's
     * end, so an entry with a line break in it, as a data set's name can
     * have, or one that ends in a carriage return, is not one it can.
     */
    public static function readsBack(string $entry): bool
    {
        return ! str_contains($entry, self::LINE_END) && ! str_ends_with($entry, self::CARRIAGE_RETURN);
    }

    /** Whether this PHPUnit selects tests by their ids: a release from 13.2 on, or a branch, taken as it is. */
    public static function selectsByIdsIn(Version $phpunit): bool
    {
        return ! $phpunit->isRelease() || version_compare($phpunit->release(), self::IDS_SINCE, '>=');
    }
}
