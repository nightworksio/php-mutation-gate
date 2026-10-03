<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

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

    /** Leaves PHPUnit's result cache as it was. */
    case DoNotCacheResult = '--do-not-cache-result';

    /** Passes a run that selects no test. */
    case DoNotFailOnEmptyTestSuite = '--do-not-fail-on-empty-test-suite';

    /** Writes the coverage map as PHP, to the file named after `=`. */
    case CoveragePhp = '--coverage-php';

    /** Logs each test's outcome as JUnit, to the file named after `=`. */
    case LogJunit = '--log-junit';
}
