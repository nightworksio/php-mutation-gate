<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function array_sum;
use function ceil;
use function count;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How many shards each package of a cut is cut into: by `shards.seconds`, to
 * `shards.target`, or exactly as `--shards=<n>` asks, never more than
 * `shards.max` in all while every package fits, none past `shards.jobLimit`
 * while the most leaves room, and none whose share of its package's cost is
 * below `shards.cutAt` times its overhead (ADR-0013, ADR-0114).
 *
 * @internal
 */
final readonly class ShardSizing
{
    /** How many times its overhead a shard's share of the cost must be at least, for the cut to make it. */
    public const float WORK_PER_OVERHEAD = 2.0;

    /**
     * @param Seconds|Absent $size     the size of a shard, where the config sets it; none where the workload decides
     * @param Seconds|Absent $target   the wall time the shards are cut to fit, where the config asks for one
     * @param Seconds|Absent $jobLimit the longest a shard's job may run, where the config sets it
     * @param float          $scale    how many seconds of the cost a second of a shard's wall time holds
     */
    private function __construct(
        private Seconds|Absent $size,
        private int $most,
        private bool $exact,
        private Seconds|Absent $target,
        private Seconds $overhead,
        private float $workPerOverhead = self::WORK_PER_OVERHEAD,
        private Seconds|Absent $jobLimit = new Absent(),
        private float $scale = 1.0,
        private bool $streamed = false,
        private bool $billed = false,
        private TailRule|Absent $tail = new Absent(),
    ) {
    }

    /** Shards of about this size each, each set up in this overhead, at most this many of them. */
    public static function bySize(Seconds $size, Seconds $overhead, int $most): self
    {
        return new self($size, $most, exact: false, target: Absent::setting(), overhead: $overhead);
    }

    /** As many shards as fit their overhead and share of the cost into a target wall time, at most this many. */
    public static function toTarget(Seconds $target, Seconds $overhead, int $most): self
    {
        return new self(Absent::setting(), $most, exact: false, target: $target, overhead: $overhead);
    }

    /** Exactly this many shards. */
    public static function exactly(int $count): self
    {
        return new self(Absent::setting(), $count, exact: true, target: Absent::setting(), overhead: Seconds::none());
    }

    /** Whether the count was asked for, so the cut makes exactly the most there may be. */
    public function exact(): bool
    {
        return $this->exact;
    }

    /** The most shards there may be, or the count asked for. */
    public function most(): int
    {
        return $this->most;
    }

    /**
     * This sizing, at most this many shards; a count asked for, or one of
     * streamed shards, which one matrix of jobs bounds, is kept as it is.
     */
    public function atMost(int $most): self
    {
        return $this->exact || $this->streamed ? $this : $this->pooledAt($most, $this->jobLimit);
    }

    /**
     * This sizing, its jobs' tail decided by this rule (ADR-0303): where they
     * run their own tail and come to more than the pool, cut to the pool,
     * each within the stop of a job that runs its tail; a count asked for is
     * kept as asked.
     */
    public function tailing(TailRule $tail): self
    {
        return new self(
            $this->size,
            $this->most,
            $this->exact,
            $this->target,
            $this->overhead,
            $this->workPerOverhead,
            $this->jobLimit,
            $this->scale,
            $this->streamed,
            $this->billed,
            $tail,
        );
    }

    /**
     * How the jobs of the cut these packages make stop.
     *
     * @param list<PackageWork> $packages
     */
    public function tail(array $packages): JobTail
    {
        return $this->tail instanceof TailRule
            ? $this->tail->job(array_sum($this->cutCounts($packages)))
            : JobTail::unknown();
    }

    /** This sizing, making no shard whose share of the cost is below this many times its overhead. */
    public function cuttingAt(float $times): self
    {
        return new self(
            $this->size,
            $this->most,
            $this->exact,
            $this->target,
            $this->overhead,
            $times,
            $this->jobLimit,
            $this->scale,
            $this->streamed,
            $this->billed,
            $this->tail,
        );
    }

    /** This sizing, its shards no longer than this while the most there may be leaves room; a count as asked. */
    public function withinJobLimit(Seconds $limit): self
    {
        return $this->exact ? $this : $this->pooledAt($this->most, $limit);
    }

    /** This sizing, each shard's overhead this much longer. */
    public function opening(Seconds $run): self
    {
        return new self(
            $this->size,
            $this->most,
            $this->exact,
            $this->target,
            Seconds::of($this->overhead->seconds() + $run->seconds()),
            $this->workPerOverhead,
            $this->jobLimit,
            $this->scale,
            $this->streamed,
            $this->billed,
            $this->tail,
        );
    }

    /**
     * This sizing for shards whose split streams judge one part of the test
     * files each (ADR-0195): fitted to the target, `shards.target` or ten
     * minutes where it sets none, a shard's wall seconds being its share of
     * the cost spread over these streams' processes, its second pass scaled,
     * and as many shards as one matrix of jobs holds, those past what runs
     * at once waiting their turn; a count asked for is kept as asked.
     */
    public function streamedOver(StreamWall $wall): self
    {
        return $this->exact
            ? $this
            : new self(
                Absent::setting(),
                LeftoverCut::MATRIX_MOST,
                $this->exact,
                $this->target instanceof Seconds ? $this->target : Seconds::of(Shards::SECONDS),
                $this->overhead,
                $this->workPerOverhead,
                $this->jobLimit,
                $wall->scale(),
                streamed: true,
                billed: $this->billed,
                tail: $this->tail,
            );
    }

    /**
     * This sizing, each package's count the one its jobs bill the fewest
     * minutes at, each job's time rounded up to a whole minute, from the
     * count it fits in up to what the most there may be leaves room for; a
     * tie goes to more shards, and a count asked for is kept as asked (ADR-0240).
     */
    public function billedByTheMinute(): self
    {
        return $this->exact
            ? $this
            : new self(
                $this->size,
                $this->most,
                $this->exact,
                $this->target,
                $this->overhead,
                $this->workPerOverhead,
                $this->jobLimit,
                $this->scale,
                $this->streamed,
                billed: true,
                tail: $this->tail,
            );
    }

    /**
     * How many shards each package is cut into. Where that comes to more than
     * the most there may be, every shard is made larger, until it fits or
     * every package has one shard. Where the jobs run their own tail and come
     * to more than the pool, the pool's shards, each made larger only up to
     * the share of its stop a job that runs its tail is filled to: more
     * shards than the pool where the cost needs them, running in waves, never
     * a job past its limit (ADR-0303).
     *
     * @param  list<PackageWork> $packages
     * @return list<int>
     */
    public function counts(array $packages): array
    {
        $counts = $this->cutCounts($packages);

        return ! $this->exact && $this->tail instanceof TailRule && $this->tail->folds(array_sum($counts))
            ? $this->pooledAt($this->tail->pool(), $this->tail->filled())->cutCounts($packages, withinLimit: true)
            : $counts;
    }

    /** This sizing, at most this many shards, each no longer than this. */
    private function pooledAt(int $most, Seconds|Absent $jobLimit): self
    {
        return new self(
            $this->size,
            $most,
            $this->exact,
            $this->target,
            $this->overhead,
            $this->workPerOverhead,
            $jobLimit,
            $this->scale,
            $this->streamed,
            $this->billed,
            $this->tail,
        );
    }

    /**
     * @param  list<PackageWork> $packages
     * @param  bool              $withinLimit whether no shard grows past its job's limit, more shards running in waves
     * @return list<int>
     */
    private function cutCounts(array $packages, bool $withinLimit = false): array
    {
        $cost = array_sum(array_map(static fn(PackageWork $package): float => $package->cost()->seconds(), $packages));
        $size = match (true) {
            $this->size instanceof Seconds => $this->size->seconds(),
            $this->target instanceof Seconds => $this->sizeToFit($this->target, $cost),
            default => $cost / $this->most,
        };
        $room = $this->room();
        $bound = $withinLimit && $room > 0.0;
        $counts = $this->grownCounts(
            $packages,
            $room > 0.0 ? min($size, $room) : $size,
            $bound ? $room : PHP_FLOAT_MAX,
        );

        if ($this->exact) {
            return $counts;
        }

        $overheads = ShardOverheads::of($this->overhead, $this->workPerOverhead, $this->scale);
        $worth = $overheads->worthCutting($packages, $counts);
        $cut = $this->billed ? $overheads->fewestMinutes($packages, $worth, $this->most) : $worth;

        return $bound ? array_map(
            static fn(PackageWork $package, int $count): int => max($count, $package->shardsAt($room)),
            $packages,
            $cut,
        ) : $cut;
    }

    /** The seconds of the cost a shard's job holds within its limit, its overhead taken off; none where none is set. */
    private function room(): float
    {
        return $this->jobLimit instanceof Seconds
            ? ($this->jobLimit->seconds() - $this->overhead->seconds()) * $this->scale
            : 0.0;
    }

    /**
     * How many shards each package is cut into at this size, every shard
     * made larger, up to this ceiling, where they come to more than the most
     * there may be.
     *
     * @param  list<PackageWork> $packages
     * @return list<int>
     */
    private function grownCounts(array $packages, float $size, float $ceiling): array
    {
        $at = static fn(float $size): array => array_map(
            static fn(PackageWork $package): int => $package->shardsAt($size),
            $packages,
        );
        $counts = $at($size);

        while (array_sum($counts) > max($this->most, count($packages)) && $size < $ceiling) {
            $size = min($ceiling, $size * array_sum($counts) / $this->most);
            $counts = $at($size);
        }

        return $counts;
    }

    /**
     * The size of a shard at the smallest count whose shards each fit their
     * overhead and their share of the cost into the target; at the most
     * shards there may be where no count does.
     */
    private function sizeToFit(Seconds $target, float $cost): float
    {
        $room = ($target->seconds() - $this->overhead->seconds()) * $this->scale;
        $count = $room > 0.0 ? ceil($cost / $room) : $this->most;

        return $cost / max(1, min($count, $this->most));
    }
}
