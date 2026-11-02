<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use NightWorksIO\MutationGate\Core\Test\Defect;

/**
 * A list PHPUnit prints after a run, by what it names of the tests that
 * failed the run: the tests that failed, or met an error, under `There was 1
 * failure:` or `There were 2 errors:`; the tests whose issues failed it, the
 * risky ones under `There was 1 risky test:` and those PHPUnit warned of
 * under `1 test triggered 1 PHPUnit warning:`, each as `<n>) <test>`; the
 * warnings PHP or a test raised, under `1 test triggered 2 PHP warnings:` or
 * `… 1 warning:`, each as `<n>) <file>:<line>` with the tests that triggered
 * it, or with none where that line is in the test itself; or a list that
 * names none, as a list of issues that do not fail the run does (see
 * FailingIssues), and a list of errors PHP's `E_USER_ERROR` raised, under `1
 * test triggered 1 error:`.
 *
 * @internal
 */
enum PrintedList
{
    case Defects;
    case Issues;
    case Warnings;
    case Other;

    /** The kind of entry each list of issues a run's tests fail it on lists, as its header names it. */
    private const string RISKY = 'risky test';

    private const string PHPUNIT_WARNING = 'PHPUnit warning';

    private const string PHP_WARNING = 'PHP warning';

    private const string WARNING = 'warning';

    /**
     * The list a header begins, by the kind it names, as `There was` begins
     * it or as `1 test triggered` does, on a run that fails on these issues.
     */
    public static function headed(string $kind, bool $there, FailingIssues $failing): self
    {
        $list = match ($kind) {
            Defect::Failure->value => self::Defects,
            Defect::Error->value => $there ? self::Defects : self::Other,
            self::RISKY, self::PHPUNIT_WARNING => self::Issues,
            self::PHP_WARNING, self::WARNING => self::Warnings,
            default => self::Other,
        };

        return self::fails($kind, $failing) ? $list : self::Other;
    }

    /** Whether the issues a list of this kind lists fail the run; a test that failed always does. */
    private static function fails(string $kind, FailingIssues $failing): bool
    {
        return match ($kind) {
            self::RISKY => $failing->risky(),
            self::PHPUNIT_WARNING => $failing->phpunitWarning(),
            self::PHP_WARNING, self::WARNING => $failing->warning(),
            default => true,
        };
    }
}
