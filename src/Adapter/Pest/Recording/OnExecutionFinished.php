<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/** Writes how many tests a mutant's own process ran, once PHPUnit ends its run. */
final readonly class OnExecutionFinished implements ExecutionFinishedSubscriber
{
    public function __construct(private RanTests $ran)
    {
    }

    public function notify(ExecutionFinished $event): void
    {
        $this->ran->written();
    }
}
