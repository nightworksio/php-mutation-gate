<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function array_merge;
use function array_pad;
use function array_sum;
use function ceil;
use function count;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * How units are cut into shards, in path order. By size, the number of
 * shards is each package's cost over `shards.seconds`, rounded up, and never
 * more than `shards.max` in all: past that, shards grow instead. To a target
 * wall time, as `shards.target` asks (ADR-0013, decision 6), the count is the
 * smallest whose shards each fit their overhead, the opening run and
 * `shards.setup`, and their share of the cost into the target, and the cut
 * within it is by size. Exactly, as `--shards=<n>` asks, there are n shards,
 * and those the units do not fill are empty and say so. Packages never share a shard, because a shard runs in
 * one package's directory; floors may, because the verdict adds up each
 * mutant itself.
 */
final readonly class Cut
{
    /** The size of a shard where the count is fixed, which the workload decides rather than the config. */
    private const float UNSIZED = 0.0;

    private function __construct(
        private float $seconds,
        private int $most,
        private bool $exact,
        private Seconds|Absent $target,
        private Seconds $overhead,
    ) {
    }

    /** Shards of about `shards.seconds` each, at most `shards.max` of them. */
    public static function bySize(int $seconds, int $most): self
    {
        return new self($seconds, $most, exact: false, target: Absent::setting(), overhead: Seconds::of(0.0));
    }

    /** As many shards as fit a shard's setup and share of the cost into a target wall time, at most `shards.max`. */
    public static function toTarget(Seconds $target, Seconds $setup, int $most): self
    {
        return new self(self::UNSIZED, $most, exact: false, target: $target, overhead: $setup);
    }

    /** Exactly this many shards, as `--shards=<n>` asks. */
    public static function exactly(int $count): self
    {
        return new self(self::UNSIZED, $count, exact: true, target: Absent::setting(), overhead: Seconds::of(0.0));
    }

    /** This cut, where each shard's runner first spends this long on its opening run. */
    public function opening(Seconds $run): self
    {
        return clone($this, ['overhead' => Seconds::of($this->overhead->seconds() + $run->seconds())]);
    }

    public function cut(Workload $work, Trees $trees): Shards|CannotJudge
    {
        $packages = $work->byPackage();

        if ($this->exact && count($packages) > $this->most) {
            return CannotJudge::because(sprintf(
                '--shards=%d cannot hold %d packages, because packages never share a shard. Ask for %d shards or more.',
                $this->most,
                count($packages),
                count($packages),
            ));
        }

        $runs = [];

        foreach ($this->countsFor($packages) as $at => $count) {
            $runs[] = $packages[$at]->runs($count);
        }

        $cut = array_merge(...$runs);

        return Labels::of($trees, ...$this->exact ? array_pad($cut, $this->most, Run::of()) : $cut);
    }

    /**
     * How many shards each package is cut into. Where that comes to more than
     * the most there may be, every shard is made larger, until it fits or
     * every package has one shard.
     *
     * @param  list<PackageWork> $packages
     * @return list<int>
     */
    private function countsFor(array $packages): array
    {
        $size = match (true) {
            $this->exact => $this->costOf($packages) / $this->most,
            $this->target instanceof Seconds => $this->sizeToFit($this->target, $this->costOf($packages)),
            default => $this->seconds,
        };
        $counts = $this->countsAt($packages, $size);

        while (array_sum($counts) > $this->most && array_sum($counts) > count($packages)) {
            $size = $size * array_sum($counts) / $this->most;
            $counts = $this->countsAt($packages, $size);
        }

        return $counts;
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
        $count = $room > 0.0 ? (int) ceil($cost / $room) : $this->most;

        return $cost / max(1, min($count, $this->most));
    }

    /** @param list<PackageWork> $packages */
    private function costOf(array $packages): float
    {
        return array_sum(array_map(static fn(PackageWork $package): float => $package->cost()->seconds(), $packages));
    }
}
