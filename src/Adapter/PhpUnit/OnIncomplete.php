<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;

/**
 * Records a test marked incomplete as ended, neither passed nor failed. One
 * marked so in `setUp` never finishes, so this is its only end.
 */
final readonly class OnIncomplete implements MarkedIncompleteSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(MarkedIncomplete $event): void
    {
        $this->recorder->setAside($event->test()->id());
    }
}
