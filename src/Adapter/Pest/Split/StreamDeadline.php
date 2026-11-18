<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function max;

use NightWorksIO\MutationGate\Core\Config\StreamWork;

/**
 * Whether a split stream's parent keeps a deadline for the test it runs
 * (ADR-0182), and the times its slowdown a stream started in a stopped
 * one's place starts at, over the stopped one's (ADR-0184).
 *
 * @internal
 */
final readonly class StreamDeadline
{
    public function __construct(
        public bool $on = false,
        public float $backoff = StreamWork::STANDARD_BACKOFF,
    ) {
    }

    /** The slowdown a stream started in the place of one stopped at this slowdown starts at. */
    public function after(float $stopped): float
    {
        return max(1.0, $stopped) * max(1.0, $this->backoff);
    }
}
