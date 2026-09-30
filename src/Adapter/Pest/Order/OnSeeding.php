<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use Pest\Mutate\Event\Events\TestSuite\StartMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuiteSubscriber;

/**
 * Writes every mutant's order once they are all made, before any runs.
 * Pest's facade files a subscriber under the first interface it implements,
 * so this implements that one alone.
 */
final readonly class OnSeeding implements StartMutationSuiteSubscriber
{
    public function __construct(private Seeder $seeder)
    {
    }

    public function notify(StartMutationSuite $event): void
    {
        $this->seeder->seed($event->mutationSuite);
    }
}
