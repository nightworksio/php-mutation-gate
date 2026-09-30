<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Pest\Mutate\Event\Events\Test\Outcome\Timeout;
use Pest\Mutate\Event\Events\Test\Outcome\TimeoutSubscriber;

/**
 * Records a mutant stopped at Pest's time limit.
 * Pest's facade files a subscriber under the first interface it implements,
 * so this implements that one alone.
 */
final readonly class OnTimeout implements TimeoutSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Timeout $event): void
    {
        $this->recorder->outcome($event->test);
    }
}
