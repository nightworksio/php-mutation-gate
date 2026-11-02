<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * Counts a test that finished in a mutant's own process as one it ran, no longer running, and beats.
 *
 * @internal
 */
final readonly class OnTestFinished implements FinishedSubscriber
{
    public function __construct(private RanTests $ran, private Heartbeat $heartbeat, private RunOrder $order)
    {
    }

    public function notify(Finished $event): void
    {
        $this->ran->counted();
        $this->order->stopped();
        $this->heartbeat->beat();
    }
}
