<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/**
 * One shard's time, as its result file records it: its runner's opening run,
 * then the mutation of its units (ADR-0016, decision 19).
 */
final readonly class ShardTiming
{
    private function __construct(private int $shard, private Phase $openingRun, private Phase $mutate)
    {
    }

    /** Shard number `$shard`, counted from 1, with its opening run and its mutation. */
    public static function of(int $shard, Phase $openingRun, Phase $mutate): self
    {
        return new self($shard, $openingRun, $mutate);
    }

    public function shard(): int
    {
        return $this->shard;
    }

    public function openingRun(): Phase
    {
        return $this->openingRun;
    }

    public function mutate(): Phase
    {
        return $this->mutate;
    }
}
