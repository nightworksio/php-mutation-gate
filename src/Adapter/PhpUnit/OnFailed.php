<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

/** Notes that the running test failed. */
final readonly class OnFailed implements FailedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Failed $event): void
    {
        $this->recorder->ended(Outcome::Failed);
    }
}
