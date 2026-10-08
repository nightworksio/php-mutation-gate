<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_keys;
use function array_map;
use function array_merge;

use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\TestRunner\TestResult\Issues\Issue;
use PHPUnit\TestRunner\TestResult\TestResult;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * A kind of issue a test raises that PHPUnit fails a run on where its
 * configuration says so (`failOnWarning`, `failOnRisky` and the rest, or
 * `failOnAllIssues`), with no test failing: a warning, a notice or a
 * deprecation PHP or the test raised, a test PHPUnit considers risky, one it
 * marks incomplete or skipped, and a warning, notice or deprecation PHPUnit
 * raised of a test. Deprecations count where the run fails on any of them,
 * whatever triggered each.
 */
enum IssueKind
{
    case Warning;
    case Notice;
    case Deprecation;
    case Risky;
    case Incomplete;
    case Skipped;
    case PhpunitWarning;
    case PhpunitNotice;
    case PhpunitDeprecation;

    /** Whether a run under this configuration fails on an issue of this kind. */
    public function fails(Configuration $config): bool
    {
        [$on, $off] = $this->setting($config);

        return ($config->failOnAllIssues() || $on) && ! $off;
    }

    /**
     * The tests that raised an issue of this kind in a run's result, by id;
     * an issue raised outside any test names none.
     *
     * @return list<string>
     */
    public function testsIn(TestResult $result): array
    {
        return match ($this) {
            self::Warning => self::raising([...$result->warnings(), ...$result->phpWarnings()]),
            self::Notice => self::raising([...$result->notices(), ...$result->phpNotices()]),
            self::Deprecation => self::raising([...$result->deprecations(), ...$result->phpDeprecations()]),
            self::Risky => self::ids($result->testConsideredRiskyEvents()),
            self::Incomplete => self::endedBy($result->testMarkedIncompleteEvents()),
            self::Skipped => self::endedBy($result->testSkippedEvents()),
            self::PhpunitWarning => self::ids($result->testTriggeredPhpunitWarningEvents()),
            self::PhpunitNotice => self::ids($result->testTriggeredPhpunitNoticeEvents()),
            self::PhpunitDeprecation => self::ids($result->testTriggeredPhpunitDeprecationEvents()),
        };
    }

    /**
     * The `failOn` setting of this kind under a configuration, and the
     * `doNotFailOn` one that turns it off.
     *
     * @return array{bool, bool}
     */
    private function setting(Configuration $config): array
    {
        return match ($this) {
            self::Warning => [$config->failOnWarning(), $config->doNotFailOnWarning()],
            self::Notice => [$config->failOnNotice(), $config->doNotFailOnNotice()],
            self::Deprecation => [
                $config->failOnDeprecation()
                    || $config->failOnSelfDeprecation()
                    || $config->failOnDirectDeprecation()
                    || $config->failOnIndirectDeprecation(),
                $config->doNotFailOnDeprecation(),
            ],
            self::Risky => [$config->failOnRisky(), $config->doNotFailOnRisky()],
            self::Incomplete => [$config->failOnIncomplete(), $config->doNotFailOnIncomplete()],
            self::Skipped => [$config->failOnSkipped(), $config->doNotFailOnSkipped()],
            self::PhpunitWarning => [$config->failOnPhpunitWarning(), $config->doNotFailOnPhpunitWarning()],
            self::PhpunitNotice => [$config->failOnPhpunitNotice(), $config->doNotFailOnPhpunitNotice()],
            self::PhpunitDeprecation => [$config->failOnPhpunitDeprecation(), $config->doNotFailOnPhpunitDeprecation()],
        };
    }

    /**
     * @param  list<Issue>  $issues
     * @return list<string> the tests that raised them, by id
     */
    private static function raising(array $issues): array
    {
        $tests = array_map(static fn(Issue $issue): array => self::ids($issue->triggeringTests()), $issues);

        return array_merge([], ...$tests);
    }

    /**
     * @template T
     *
     * @param  array<array-key, T> $byTest what PHPUnit keeps of each test, by its id
     * @return list<string>
     */
    private static function ids(array $byTest): array
    {
        return array_map(strval(...), array_keys($byTest));
    }

    /**
     * @param  list<MarkedIncomplete|Skipped> $events
     * @return list<string> the tests they ended, by id
     */
    private static function endedBy(array $events): array
    {
        return array_map(static fn(MarkedIncomplete|Skipped $event): string => $event->test()->id(), $events);
    }
}
