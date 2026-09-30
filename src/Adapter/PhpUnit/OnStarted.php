<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/** Records the test about to be prepared, by its id, before anything of it runs. */
final readonly class OnStarted implements PreparationStartedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $this->recorder->started($event->test()->id());
    }
}
