<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Runner\Gauge;
use NightWorksIO\MutationGate\Core\Runner\Remaining;

/** What a test says the machine has left. */
final readonly class GaugeFake implements Gauge
{
    public function __construct(private Remaining $remaining)
    {
    }

    /** A machine whose system says nothing of what it has left. */
    public static function silent(): self
    {
        return new self(Remaining::unknown());
    }

    public function remaining(): Remaining
    {
        return $this->remaining;
    }
}
