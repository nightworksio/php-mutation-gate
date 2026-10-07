<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/** Counts a test that started in a mutant's own process into the order it took its tests in. */
final readonly class OnPreparationStarted implements PreparationStartedSubscriber
{
    public function __construct(private RunOrder $order)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $this->order->started($event->test()->id());
    }
}
