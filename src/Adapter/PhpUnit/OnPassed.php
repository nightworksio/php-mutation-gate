<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;

/** Notes that the running test passed. */
final readonly class OnPassed implements PassedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Passed $event): void
    {
        $this->recorder->ended(Outcome::Passed);
    }
}
