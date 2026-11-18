<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\MadeMutants;

/**
 * What a shard's run judges: the whole shard, or, in a job of the second
 * wave, one share of the mutants it left at its deadline (ADR-0190), in the
 * shard's units that hold them, making them alone, its result keeping them
 * alone, left where the verdict reads the share's.
 *
 * @internal
 */
final readonly class LeftoverScope
{
    private function __construct(private LeftoverShare|NotGiven $share)
    {
    }

    public static function of(LeftoverShare|NotGiven $share): self
    {
        return new self($share);
    }

    /** Whether the run pulls its work as the plan's shards do (ADR-0134): never for a share, which names its shard. */
    public function pulls(Plan $plan): bool
    {
        return $plan->shards()->pulls() && $this->share instanceof NotGiven;
    }

    /** The shard's units the run mutates. */
    public function shardOf(Shard $shard): Shard
    {
        return $this->share instanceof LeftoverShare
            ? $shard->holding($shard->units()->within($this->share->files()))
            : $shard;
    }

    public function made(): MadeMutants
    {
        return $this->share instanceof LeftoverShare
            ? MadeMutants::only(...$this->share->natives())
            : MadeMutants::every();
    }

    /** What the run came to, its mutants and flaky ones those it judges alone. */
    public function kept(Mutated|CannotJudge $outcome): Mutated|CannotJudge
    {
        return $this->share instanceof LeftoverShare && $outcome instanceof Mutated
            ? $outcome->keeping($this->share->mutants())
            : $outcome;
    }

    /** Where the run leaves its result in this directory of results. */
    public function resultIn(Path $results, ShardId $shard): Path
    {
        return $this->share instanceof LeftoverShare
            ? LeftoverParts::resultOf($results, $this->share)
            : Workspace::result($results, $shard);
    }
}
