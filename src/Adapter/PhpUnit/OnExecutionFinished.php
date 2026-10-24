<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Test\RunIssues;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/** Records, once PHPUnit ends a mutant's run, the tests whose issues failed it as its killers. */
final readonly class OnExecutionFinished implements ExecutionFinishedSubscriber
{
    public function __construct(private Recorder $recorder, private RunIssues $issues)
    {
    }

    public function notify(ExecutionFinished $event): void
    {
        $this->recorder->issued(...$this->issues->killers());
    }
}
