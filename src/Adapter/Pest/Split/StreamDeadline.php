<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function max;

use NightWorksIO\MutationGate\Core\Config\SlowdownCarry;
use NightWorksIO\MutationGate\Core\Config\StreamWork;

/**
 * Whether a split stream's parent keeps a deadline for the test it runs
 * (ADR-0182), and the times its slowdown a stream started in a stopped
 * one's place starts at, over the stopped one's, and where the slowdown a
 * pass's streams measured carries on to (ADR-0184).
 *
 * @internal
 */
final readonly class StreamDeadline
{
    public function __construct(
        public bool $on = false,
        public float $backoff = StreamWork::STANDARD_BACKOFF,
        public SlowdownCarry $carry = SlowdownCarry::Shard,
    ) {
    }

    /** The slowdown a stream started in the place of one stopped at this slowdown starts at. */
    public function after(float $stopped): float
    {
        return max(1.0, $stopped) * max(1.0, $this->backoff);
    }
}
