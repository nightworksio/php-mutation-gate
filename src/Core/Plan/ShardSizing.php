<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function array_sum;
use function ceil;
use function count;
use function floor;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Config\Absent;
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
     */
    private function __construct(
        private Seconds|Absent $size,
        private int $most,
        private bool $exact,
        private Seconds|Absent $target,
        private Seconds $overhead,
        private float $workPerOverhead = self::WORK_PER_OVERHEAD,
        private Seconds|Absent $jobLimit = new Absent(),
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

    /** This sizing, at most this many shards; a count asked for is kept as asked. */
    public function atMost(int $most): self
    {
        return $this->exact
            ? $this
            : new self(
                $this->size,
                $most,
                $this->exact,
                $this->target,
                $this->overhead,
                $this->workPerOverhead,
                $this->jobLimit,
            );
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
        );
    }

    /** This sizing, its shards no longer than this while the most there may be leaves room; a count as asked. */
    public function withinJobLimit(Seconds $limit): self
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
                $limit,
            );
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
        );
    }

    /**
     * How many shards each package is cut into. Where that comes to more than
     * the most there may be, every shard is made larger, until it fits or
     * every package has one shard.
     *
     * @param  list<PackageWork> $packages
     * @return list<int>
     */
    public function counts(array $packages): array
    {
        $size = match (true) {
            $this->size instanceof Seconds => $this->size->seconds(),
            $this->target instanceof Seconds => $this->sizeToFit($this->target, $this->costOf($packages)),
            default => $this->costOf($packages) / $this->most,
        };
        $size = $this->withinTheLimit($size);
        $counts = $this->countsAt($packages, $size);

        while (array_sum($counts) > max($this->most, count($packages))) {
            $size = $size * array_sum($counts) / $this->most;
            $counts = $this->countsAt($packages, $size);
        }

        return $this->exact ? $counts : $this->worthCutting($packages, $counts);
    }

    /** This size, no more than a shard's job can spend on its share of the cost once its overhead is paid. */
    private function withinTheLimit(float $size): float
    {
        $room = $this->jobLimit instanceof Seconds ? $this->jobLimit->seconds() - $this->overhead->seconds() : 0.0;

        return $room > 0.0 ? min($size, $room) : $size;
    }

    /**
     * These counts, each lowered to the most shards whose share of the
     * package's cost is at least `shards.cutAt` times their overhead. A package lowered to
     * none still runs, in one shard, as each package does.
     *
     * @param  list<PackageWork> $packages
     * @param  list<int>         $counts
     * @return list<int>
     */
    private function worthCutting(array $packages, array $counts): array
    {
        $least = $this->overhead->seconds() * $this->workPerOverhead;

        return array_map(
            static fn(PackageWork $package, int $count): int => $least > 0.0
                ? min($count, (int) floor($package->cost()->seconds() / $least))
                : $count,
            $packages,
            $counts,
        );
    }

    /**
     * @param  list<PackageWork> $packages
     * @return list<int>
     */
    private function countsAt(array $packages, float $size): array
    {
        return array_map(static fn(PackageWork $package): int => $package->shardsAt($size), $packages);
    }

    /**
     * The size of a shard at the smallest count whose shards each fit their
     * overhead and their share of the cost into the target; at the most
     * shards there may be where no count does.
     */
    private function sizeToFit(Seconds $target, float $cost): float
    {
        $room = $target->seconds() - $this->overhead->seconds();
        $count = $room > 0.0 ? ceil($cost / $room) : $this->most;

        return $cost / max(1, min($count, $this->most));
    }

    /** @param list<PackageWork> $packages */
    private function costOf(array $packages): float
    {
        return array_sum(array_map(static fn(PackageWork $package): float => $package->cost()->seconds(), $packages));
    }
}
