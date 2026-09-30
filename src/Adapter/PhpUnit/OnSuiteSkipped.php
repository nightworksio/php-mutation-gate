<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\TestSuite\Skipped;
use PHPUnit\Event\TestSuite\SkippedSubscriber;

/**
 * Records every test of a suite skipped whole, such as in
 * `setUpBeforeClass`, as ended, neither passed nor failed: none of them
 * starts or finishes, so this is their only end.
 */
final readonly class OnSuiteSkipped implements SkippedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Skipped $event): void
    {
        foreach ($event->testSuite()->tests() as $test) {
            $this->recorder->setAside($test->id());
        }
    }
}
