<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Pest\Mutate\Event\Events\Test\Outcome\Untested;
use Pest\Mutate\Event\Events\Test\Outcome\UntestedSubscriber;

/**
 * Records a mutant every test passed with.
 * Pest's facade files a subscriber under the first interface it implements,
 * so this implements that one alone.
 */
final readonly class OnUntested implements UntestedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Untested $event): void
    {
        $this->recorder->outcome($event->test);
    }
}
