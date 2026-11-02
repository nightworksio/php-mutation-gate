<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Notes when a test of a coverage run was prepared, to time it from (see TimedTests).
 *
 * @internal
 */
final readonly class OnTimedPrepared implements PreparedSubscriber
{
    public function __construct(private TimedTests $timed)
    {
    }

    public function notify(Prepared $event): void
    {
        $this->timed->prepared($event->test()->id(), $event->telemetryInfo()->time());
    }
}
