<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Pest\Mutate\Event\Events\TestSuite\StartMutationGeneration;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGenerationSubscriber;

/**
 * Keeps the opening run's coverage map before Pest deletes it.
 * Pest's facade files a subscriber under the first interface it implements,
 * so this implements that one alone.
 */
final readonly class OnStartMutationGeneration implements StartMutationGenerationSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(StartMutationGeneration $event): void
    {
        $this->recorder->keepCoverage();
    }
}
