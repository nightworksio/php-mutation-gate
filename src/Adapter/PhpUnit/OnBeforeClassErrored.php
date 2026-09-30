<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\Test\BeforeFirstTestMethodErrored;
use PHPUnit\Event\Test\BeforeFirstTestMethodErroredSubscriber;

/**
 * Records a test class whose `setUpBeforeClass` errored: none of its tests
 * starts, and each of them the run selected ended as though it had errored.
 */
final readonly class OnBeforeClassErrored implements BeforeFirstTestMethodErroredSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(BeforeFirstTestMethodErrored $event): void
    {
        $this->recorder->classFailed($event->testClassName());
    }
}
