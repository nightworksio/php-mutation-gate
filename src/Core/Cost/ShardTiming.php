<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/**
 * One shard's time, as its result file records it: when it began, how long
 * it took, and the steps its time went to, each from when it began
 * (ADR-0016, decision 19).
 */
final readonly class ShardTiming
{
    private function __construct(private int $shard, private Phase $whole, private StepTimes $steps)
    {
    }

    /** Shard number `$shard`, counted from 1, timed whole, with the steps its time went to. */
    public static function of(int $shard, Phase $whole, StepTimes $steps): self
    {
        return new self($shard, $whole, $steps);
    }

    public function shard(): int
    {
        return $this->shard;
    }

    public function whole(): Phase
    {
        return $this->whole;
    }

    public function steps(): StepTimes
    {
        return $this->steps;
    }

    /** When one of its steps began, after the shard's start, and how long it took. */
    public function phaseOf(StepTime $step): Phase
    {
        return Phase::of($this->whole->start(), $step->took())->later($this->whole->after())->later($step->since());
    }
}
