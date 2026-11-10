<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

/**
 * How a test ended in a stream's parent, as a run planned without a fresh
 * map records it (ADR-0135): passed or not, whether it raised an issue,
 * whether it ran in a process of its own, and in how many seconds.
 *
 * @internal
 */
final readonly class TestEnded
{
    public function __construct(
        public bool $passed,
        public bool $troubled,
        public bool $own,
        public float $seconds,
    ) {
    }
}
