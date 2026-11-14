<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationErrored;
use PHPUnit\Event\Test\PreparationErroredSubscriber;
use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\PreparationFailedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/**
 * Tells a split stream where it stands in PHPUnit's run (ADR-0117): a test
 * begins as its preparation starts, so its hooks run within it; it fails
 * where it, or its preparation, failed or errored; it ends as it finishes;
 * a test that runs in a process of its own forks nothing; a test that
 * raises an issue is troubled; the run ends as its execution finishes.
 *
 * @internal
 */
final readonly class StreamEvents
{
    public static function registerIn(Facade $events): void
    {
        $events->registerSubscribers(
            new class implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    $test = $event->test();
                    Stream::began($test->id());

                    if ($test instanceof TestMethod && StreamEvents::isolates($test)) {
                        Stream::isolated();
                    }
                }
            },
            new class implements PreparationErroredSubscriber {
                public function notify(PreparationErrored $event): void
                {
                    Stream::failed();
                }
            },
            new class implements PreparationFailedSubscriber {
                public function notify(PreparationFailed $event): void
                {
                    Stream::failed();
                }
            },
            new class implements FailedSubscriber {
                public function notify(Failed $event): void
                {
                    Stream::failed();
                }
            },
            new class implements ErroredSubscriber {
                public function notify(Errored $event): void
                {
                    Stream::failed();
                }
            },
            new class implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    Stream::ended();
                }
            },
            new class implements ExecutionFinishedSubscriber {
                public function notify(ExecutionFinished $event): void
                {
                    Stream::finished();
                }
            },
            ...StreamIssues::subscribers(),
        );
    }

    /** Whether the test runs in a process of its own, where no fork of this one reaches it. */
    public static function isolates(TestMethod $test): bool
    {
        $metadata = $test->metadata();

        return $metadata->isRunInSeparateProcess()->isNotEmpty()
            || $metadata->isRunTestsInSeparateProcesses()->isNotEmpty();
    }
}
