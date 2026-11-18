<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function max;

use NightWorksIO\MutationGate\Core\Config\FirstFork;
use NightWorksIO\MutationGate\Core\Config\SlowdownCarry;
use NightWorksIO\MutationGate\Core\Config\StreamWork;

/**
 * How a split-stream run's streams get through their work, as the gate tells
 * Pest's own process: whether a stream's parent keeps a deadline for the test
 * it runs (ADR-0182), the times its slowdown a stream started in a stopped
 * one's place starts at, over the stopped one's, where the slowdown a pass's
 * streams measured carries on to (ADR-0184), where a first-pass stream
 * forks a mutant the ledger names no likely killer of (ADR-0187), and how
 * many parts a pass cuts its tests into for each stream it runs at once
 * (ADR-0188).
 *
 * @internal
 */
final readonly class StreamPace
{
    public function __construct(
        public bool $on = false,
        public float $backoff = StreamWork::STANDARD_BACKOFF,
        public SlowdownCarry $carry = SlowdownCarry::Shard,
        public FirstFork $firstFork = FirstFork::Cheapest,
        public int $partsPerProcess = StreamWork::STANDARD_PARTS_PER_PROCESS,
    ) {
    }

    /** The slowdown a stream started in the place of one stopped at this slowdown starts at. */
    public function after(float $stopped): float
    {
        return max(1.0, $stopped) * max(1.0, $this->backoff);
    }
}
