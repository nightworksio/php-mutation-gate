<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * Records a skipped test as ended, neither passed nor failed. A test skipped
 * before it was prepared, in `setUp` or by a requirement it does not meet,
 * never finishes, so this is its only end.
 */
final readonly class OnSkipped implements SkippedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Skipped $event): void
    {
        $this->recorder->setAside($event->test()->id());
    }
}
