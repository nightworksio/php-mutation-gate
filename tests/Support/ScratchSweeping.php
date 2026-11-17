<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/** Sweeps the scratch directories each test made once it has finished, whether or not its file sweeps them. */
final readonly class ScratchSweeping implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new readonly class implements FinishedSubscriber {
            /** Removes every scratch directory the test made. */
            public function notify(Finished $event): void
            {
                Scratch::sweep();
            }
        });
    }
}
