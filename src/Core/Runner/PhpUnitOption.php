<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\SuiteName;

use function sprintf;
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

    /** Lists the suite's groups and runs no test. */
    case ListGroups = '--list-groups';

    /** Prints no colour, whatever the project's config asks, so what PHPUnit prints can be read back. */
    case NoColors = '--colors=never';

    /** Logs each test's outcome as JUnit, to the file named after `=`. */
    case LogJunit = '--log-junit';

    /** The first PHPUnit that names its result cache the test run history, and deprecates `--do-not-cache-result`. */
    private const string HISTORY_SINCE = '13.3.0';

    /**
     * The option that keeps a run to one suite's tests, as `--suite` asks
     * (ADR-0025, decision 9); none where the run is narrowed to no suite.
     *
     * @return list<string>
     */
    public static function inSuite(SuiteName|NotGiven $suite): array
    {
        return $suite instanceof SuiteName ? [sprintf('%s=%s', self::TestSuite->value, $suite->value())] : [];
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
}
