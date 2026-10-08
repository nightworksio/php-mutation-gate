<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_values;
use function file_put_contents;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use PHPUnit\Event\Test\PhpunitDeprecationTriggered;
use PHPUnit\Event\Test\PhpunitErrorTriggered;
use PHPUnit\Event\Test\PhpunitNoticeTriggered;
use PHPUnit\Event\Test\PhpunitWarningTriggered;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Event\TestRunner\WarningTriggered;
use PHPUnit\Event\TestRunner\WarningTriggeredSubscriber;
use PHPUnit\TestRunner\TestResult\Issues\Issue;
use PHPUnit\TestRunner\TestResult\TestResult;
use PHPUnit\TextUI\CliArguments\Builder;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Merger;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use PHPUnit\TextUI\XmlConfiguration\Loader;

use function sprintf;

/**
 * PHPUnit's configuration and result as a run ends, built without touching
 * the registry or the result of the run that runs the tests.
 */
final class PhpUnitResults
{
    /** The configuration these command line options give, read with no XML file. */
    public static function configured(string ...$options): Configuration
    {
        $cli = new Builder()->fromParameters(array_values(['--no-configuration', ...$options]));

        return new Merger()->merge($cli, DefaultConfiguration::create());
    }

    /**
     * The configuration an XML file that sets this attribute of `<phpunit>`
     * to true gives, with these command line options over it: how a project
     * turns off on the command line what its file turns on, which PHPUnit
     * takes without the warning it gives where both are options.
     */
    public static function configuredOver(string $attribute, string ...$options): Configuration
    {
        $file = sprintf('%s/phpunit.xml', Scratch::directory());
        file_put_contents($file, sprintf('<?xml version="1.0"?>%s<phpunit %s="true"/>%s', PHP_EOL, $attribute, PHP_EOL));
        $cli = new Builder()->fromParameters(array_values(['--configuration', $file, ...$options]));

        return new Merger()->merge($cli, new Loader()->load($file));
    }

    /**
     * A result of one test run that holds only what is given.
     *
     * @param list<Issue>                                $warnings
     * @param list<Issue>                                $phpWarnings
     * @param list<Issue>                                $notices
     * @param list<Issue>                                $phpDeprecations
     * @param array<string, list<ConsideredRisky>>         $risky                by test
     * @param array<string, list<PhpunitWarningTriggered>> $phpunitWarnings      by test
     * @param array<string, list<PhpunitNoticeTriggered>>  $phpunitNotices       by test
     * @param array<string, list<PhpunitDeprecationTriggered>> $phpunitDeprecations by test
     * @param array<string, list<PhpunitErrorTriggered>>   $phpunitErrors        by test
     * @param list<Skipped>                                $skipped
     * @param list<MarkedIncomplete>                       $incomplete
     * @param list<WarningTriggered>                       $runnerWarnings
     */
    public static function result(
        array $warnings = [],
        array $phpWarnings = [],
        array $notices = [],
        array $phpDeprecations = [],
        array $risky = [],
        array $phpunitWarnings = [],
        array $phpunitNotices = [],
        array $phpunitDeprecations = [],
        array $phpunitErrors = [],
        array $skipped = [],
        array $incomplete = [],
        array $runnerWarnings = [],
    ): TestResult {
        return new TestResult(
            1,
            1,
            1,
            [],
            [],
            $risky,
            [],
            $skipped,
            $incomplete,
            $phpunitDeprecations,
            $phpunitErrors,
            $phpunitNotices,
            $phpunitWarnings,
            [],
            [],
            $runnerWarnings,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            $notices,
            $warnings,
            $phpDeprecations,
            [],
            $phpWarnings,
            0,
            ['self' => 0, 'direct' => 0, 'indirect' => 0, 'unknown' => 0],
        );
    }

    /** An issue this test raised. */
    public static function issue(Test $test): Issue
    {
        return Issue::from('/p/src/Money.php', 7, 'Undefined array key -1', $test);
    }

    /** The event PHPUnit emits where the test running it is skipped. */
    public static function skipped(): Skipped
    {
        $events = new Facade();
        $seen = new class implements SkippedSubscriber {
            /** @var list<Skipped> */
            public private(set) array $events = [];

            public function notify(Skipped $event): void
            {
                $this->events[] = $event;
            }
        };
        $events->registerSubscriber($seen);
        PhpUnitEvents::skipped($events);

        return $seen->events[0];
    }

    /** The event PHPUnit emits where its runner warns, outside any test. */
    public static function runnerWarning(): WarningTriggered
    {
        $events = new Facade();
        $seen = new class implements WarningTriggeredSubscriber {
            /** @var list<WarningTriggered> */
            public private(set) array $events = [];

            public function notify(WarningTriggered $event): void
            {
                $this->events[] = $event;
            }
        };
        $events->registerSubscriber($seen);
        PhpUnitEvents::runnerWarned($events);

        return $seen->events[0];
    }

    /** The event PHPUnit emits where the test running it is marked incomplete. */
    public static function incomplete(): MarkedIncomplete
    {
        $events = new Facade();
        $seen = new class implements MarkedIncompleteSubscriber {
            /** @var list<MarkedIncomplete> */
            public private(set) array $events = [];

            public function notify(MarkedIncomplete $event): void
            {
                $this->events[] = $event;
            }
        };
        $events->registerSubscriber($seen);
        PhpUnitEvents::incomplete($events);

        return $seen->events[0];
    }
}
