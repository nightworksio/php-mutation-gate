<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\Test\BeforeFirstTestMethodFailed;
use PHPUnit\Event\Test\BeforeFirstTestMethodFailedSubscriber;

/**
 * Records a test class whose `setUpBeforeClass` failed: none of its tests
 * starts, and each of them the run selected ended as though it had failed.
 */
final readonly class OnBeforeClassFailed implements BeforeFirstTestMethodFailedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(BeforeFirstTestMethodFailed $event): void
    {
        $this->recorder->classFailed($event->testClassName());
    }
}
