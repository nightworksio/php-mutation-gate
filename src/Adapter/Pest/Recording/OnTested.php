<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Pest\Mutate\Event\Events\Test\Outcome\Tested;
use Pest\Mutate\Event\Events\Test\Outcome\TestedSubscriber;

/**
 * Records a mutant a test caught.
 * Pest's facade files a subscriber under the first interface it implements,
 * so this implements that one alone.
 */
final readonly class OnTested implements TestedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Tested $event): void
    {
        $this->recorder->outcome($event->test);
    }
}
