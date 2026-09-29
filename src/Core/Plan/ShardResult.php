<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/**
 * What one shard left for the verdict: the plan it followed, its units with
 * their keys, every mutant's record or the runner's cannot judge, and what it
 * measured.
 */
final readonly class ShardResult
{
    private function __construct(
        private Digest $plan,
        private ShardId $shard,
        private Keys $units,
        private MutationResult|CannotJudge $outcome,
        private Measurement $measured,
    ) {
    }

    public static function of(
        Digest $plan,
        ShardId $shard,
        Keys $units,
        MutationResult|CannotJudge $outcome,
        Measurement $measured,
    ): self {
        return new self($plan, $shard, $units, $outcome, $measured);
    }

    /** The digest of the plan the shard followed. */
    public function plan(): Digest
    {
        return $this->plan;
    }

    public function shard(): ShardId
    {
        return $this->shard;
    }

    /** The shard's units, each with its content key or why it has none. */
    public function units(): Keys
    {
        return $this->units;
    }

    /** Every mutant the runner reported, or why it could not judge, with its output. */
    public function outcome(): MutationResult|CannotJudge
    {
        return $this->outcome;
    }

    public function measured(): Measurement
    {
        return $this->measured;
    }
}
