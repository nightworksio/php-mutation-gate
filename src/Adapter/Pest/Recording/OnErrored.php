<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

/** Names a test that errored in a mutant's own process as one that killed it. */
final readonly class OnErrored implements ErroredSubscriber
{
    public function __construct(private Killers $killers)
    {
    }

    public function notify(Errored $event): void
    {
        $this->killers->killedBy($event->test()->id());
    }
}
