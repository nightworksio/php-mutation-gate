<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\PeakMemory;

/** The peak memory a test says the gate's processes held, or none the system counts. */
final readonly class PeakMemoryFake implements PeakMemory
{
    public function __construct(private MemoryCap|NotGiven $peak)
    {
    }

    public function peak(): MemoryCap|NotGiven
    {
        return $this->peak;
    }
}
