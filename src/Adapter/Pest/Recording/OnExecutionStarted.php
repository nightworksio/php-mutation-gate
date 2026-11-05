<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_map;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;

/**
 * Beats as a mutant's own process begins its tests, once every test file it
 * loads is loaded, and writes how many tests it is to run, and in what order.
 *
 * @internal
 */
final readonly class OnExecutionStarted implements ExecutionStartedSubscriber
{
    public function __construct(private Heartbeat $heartbeat, private Killers $killers)
    {
    }

    public function notify(ExecutionStarted $event): void
    {
        $this->heartbeat->beat();
        $this->killers->ordered(...array_map(
            static fn(Test $test): string => $test->id(),
            [...$event->testSuite()->tests()],
        ));
    }
}
