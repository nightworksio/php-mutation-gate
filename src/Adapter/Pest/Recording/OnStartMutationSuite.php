<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Pest\Mutate\Event\Events\TestSuite\StartMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuiteSubscriber;

/**
 * Records every mutant once they are all made.
 * Pest's facade files a subscriber under the first interface it implements,
 * so this implements that one alone.
 */
final readonly class OnStartMutationSuite implements StartMutationSuiteSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(StartMutationSuite $event): void
    {
        $this->recorder->planned($event->mutationSuite);
    }
}
