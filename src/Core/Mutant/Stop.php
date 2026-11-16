<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * Where a limit stopped a mutant: the limit, and what the unmutated code
 * needs of it. For one that timed out, the seconds the runner allowed it and
 * the seconds its judging tests take on their own, which timeout triage
 * compares; for one that ran out of the memory cap, the cap and the most
 * memory the unmutated suite's largest process held, which memory triage
 * compares. Each is unmeasured where nobody said.
 */
final readonly class Stop
{
    private function __construct(
        private Seconds|MemoryCap|Unmeasured $limit,
        private Seconds|MemoryCap|Unmeasured $need,
    ) {
    }

    public static function unmeasured(): self
    {
        return new self(Unmeasured::duration(), Unmeasured::duration());
    }

    /** This stop, at the limit that stopped the mutant: the seconds its runner allowed it, or the memory cap. */
    public function at(Seconds|MemoryCap $limit): self
    {
        return new self($limit, $this->need);
    }

    /** This stop, the unmutated code needing this much of its limit. */
    public function needing(Seconds|MemoryCap $need): self
    {
        return new self($this->limit, $need);
    }

    /** The limit that stopped the mutant, where it says: the seconds the runner allowed it, or the memory cap. */
    public function limit(): Seconds|MemoryCap|Unmeasured
    {
        return $this->limit;
    }

    /** What the unmutated code needs of the limit, where that was measured. */
    public function need(): Seconds|MemoryCap|Unmeasured
    {
        return $this->need;
    }
}
