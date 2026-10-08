<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/** Counts a test that finished in a replay, which stops once it has run as many as the run it replays. */
final readonly class OnReplayFinished implements FinishedSubscriber
{
    public function __construct(private ReplayStop $stop)
    {
    }

    public function notify(Finished $event): void
    {
        $this->stop->finished();
    }
}
