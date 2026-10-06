<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;

/** Beats as a mutant's own process begins its tests, once every test file it loads is loaded. */
final readonly class OnExecutionStarted implements ExecutionStartedSubscriber
{
    public function __construct(private Heartbeat $heartbeat)
    {
    }

    public function notify(ExecutionStarted $event): void
    {
        $this->heartbeat->beat();
    }
}
