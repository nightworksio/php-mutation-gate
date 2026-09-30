<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

/** Notes that the running test errored. */
final readonly class OnErrored implements ErroredSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Errored $event): void
    {
        $this->recorder->ended(Outcome::Errored);
    }
}
