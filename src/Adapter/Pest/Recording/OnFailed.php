<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

/** Names a test that failed in a mutant's own process as one that killed it. */
final readonly class OnFailed implements FailedSubscriber
{
    public function __construct(private Killers $killers)
    {
    }

    public function notify(Failed $event): void
    {
        $this->killers->killedBy($event->test()->id());
    }
}
