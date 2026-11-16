<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactClaims;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\PullQueue;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

/**
 * The items a job of a plan whose shards pull their work has left to claim,
 * the claims it takes them through, and what it left of those it ran. Where
 * the claims cannot be reached, or fail, the job runs its own items alone,
 * and says so (ADR-0134, ADR-0135).
 *
 * @internal
 */
final readonly class PulledItems
{
    /** @param bool $took whether the job takes the item it claimed last */
    private function __construct(
        private ArtifactClaims|CannotJudge $claims,
        private PullQueue $queue,
        private ShardsLeft $left,
        private ShardId $job,
        private bool $took = false,
    ) {
    }

    /** The items of this queue, claimed through these claims, none of them run yet. */
    public static function of(ArtifactClaims|CannotJudge $claims, PullQueue $queue, ShardId $job): self
    {
        return $claims instanceof CannotJudge
            ? self::alone($claims, $queue, ShardsLeft::none($job), $job)
            : new self($claims, $queue, ShardsLeft::none($job), $job);
    }

    /** The next item to claim, or none once the queue is empty. */
    public function next(): Shard|NotGiven
    {
        return $this->queue->next();
    }

    public function job(): ShardId
    {
        return $this->job;
    }

    /** What the job left of the items it ran. */
    public function left(): ShardsLeft
    {
        return $this->left;
    }

    /** Whether the job takes the item it claimed last. */
    public function took(): bool
    {
        return $this->took;
    }

    /** These items once the job claimed this one: taken where its claim holds, or, alone, where it is the job's own. */
    public function claiming(Shard $item): self
    {
        $queue = $this->queue->without($item->id());
        $claim = $this->claims instanceof ArtifactClaims ? $this->claims->take($item->id()) : true;

        return $claim instanceof CannotJudge
            ? self::alone($claim, $queue, $this->left, $this->job, $item->worker()->number() === $this->job->number())
            : new self($this->claims, $queue, $this->left, $this->job, $claim);
    }

    /** These items once the job ran this one and left what it came to, its claim finished. */
    public function finished(Shard $item, ShardLeft $written): self
    {
        $left = $this->left->with($written);
        $finished = $this->claims instanceof ArtifactClaims ? $this->claims->finish($item->id()) : $item->id();

        return $finished instanceof CannotJudge
            ? self::alone($finished, $this->queue, $left, $this->job)
            : new self($this->claims, $this->queue, $left, $this->job);
    }

    /** These items, run alone because the claims failed for this reason: the job's own only. */
    private static function alone(
        CannotJudge $why,
        PullQueue $queue,
        ShardsLeft $left,
        ShardId $job,
        bool $took = false,
    ): self {
        return new self($why, $queue->ownAlone(), $left->alone($why), $job, $took);
    }
}
