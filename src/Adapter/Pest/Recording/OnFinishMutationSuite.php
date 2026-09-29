<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuiteSubscriber;

/**
 * Records every mutant's final status and duration.
 * Pest's facade files a subscriber under the first interface it implements,
 * so this implements that one alone.
 */
final readonly class OnFinishMutationSuite implements FinishMutationSuiteSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(FinishMutationSuite $event): void
    {
        $this->recorder->finished($event->mutationSuite);
    }
}
