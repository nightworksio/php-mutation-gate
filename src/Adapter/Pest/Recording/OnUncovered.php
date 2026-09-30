<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Pest\Mutate\Event\Events\Test\Outcome\Uncovered;
use Pest\Mutate\Event\Events\Test\Outcome\UncoveredSubscriber;

/**
 * Records a mutant no test ran.
 * Pest's facade files a subscriber under the first interface it implements,
 * so this implements that one alone.
 */
final readonly class OnUncovered implements UncoveredSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Uncovered $event): void
    {
        $this->recorder->outcome($event->test);
    }
}
