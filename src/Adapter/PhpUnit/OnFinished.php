<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/** Records the test that finished, by its id, with how it ended. */
final readonly class OnFinished implements FinishedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Finished $event): void
    {
        $this->recorder->finished($event->test()->id());
    }
}
