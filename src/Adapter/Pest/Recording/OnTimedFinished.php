<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * Writes how long a test of a coverage run took, once it finished (see TimedTests).
 *
 * @internal
 */
final readonly class OnTimedFinished implements FinishedSubscriber
{
    public function __construct(private TimedTests $timed)
    {
    }

    public function notify(Finished $event): void
    {
        $this->timed->finished($event->test()->id(), $event->telemetryInfo()->time());
    }
}
