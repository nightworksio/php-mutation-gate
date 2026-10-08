<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/** Takes a test that started in a replay into the order the replay digests. */
final readonly class OnReplayStarted implements PreparationStartedSubscriber
{
    public function __construct(private ReplayStop $stop)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $this->stop->started($event->test()->id());
    }
}
