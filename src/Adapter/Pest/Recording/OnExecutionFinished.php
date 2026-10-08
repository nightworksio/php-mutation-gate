<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/**
 * Writes, once PHPUnit ends a mutant's own run, the tests whose issues failed
 * it as its killers, then how many tests it ran.
 */
final readonly class OnExecutionFinished implements ExecutionFinishedSubscriber
{
    public function __construct(private RanTests $ran, private Killers $killers, private RunIssues $issues)
    {
    }

    public function notify(ExecutionFinished $event): void
    {
        $this->killers->issuedBy(...$this->issues->killers());
        $this->ran->written();
    }
}
