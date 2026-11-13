<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_pad;
use function array_sum;
use function ceil;
use function count;
use function floor;
use function intdiv;
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
 * wall time, as `shards.target` asks (ADR-0013), the count is the
 * smallest whose shards each fit their overhead, the opening run and
 * `shards.setup`, and their share of the cost into the target, and the cut
 * within it is by size. Either way, no shard is cut past the limit its job
 * has, `shards.jobLimit`, while `shards.max` leaves room, and no package is
 * cut into shards whose share of its cost is below `shards.cutAt` times
 * their overhead, twice by default (ADR-0013, ADR-0114).
 * Exactly, as `--shards=<n>` asks, there are n shards,
 * and those the units do not fill are empty and say so. Where a run that
 * splits its streams shards by tests (ADR-0117), each of a package's shards
 * takes every unit of it and its own part of the test files, n parts for n
 * shards, its cost the package's over n. Packages never share a shard,
 * because a shard runs in one package's directory; floors may, because the
 * verdict adds up each mutant itself.
 *
 * @internal
 */
final readonly class Cut
{
    /** How many times its overhead a shard's share of the cost must be at least, for the cut to make it. */
    public const float WORK_PER_OVERHEAD = 2.0;

    /**
     * @param Seconds|Absent $size   the size of a shard, where the config sets it; none where the workload decides
     * @param Seconds|Absent $target the wall time the shards are cut to fit, where the config asks for one
     */
    private function __construct(
        private Seconds|Absent $size,
        private int $most,
        private bool $exact,
        private Seconds|Absent $target,
        private Seconds $overhead,
        private float $workPerOverhead = self::WORK_PER_OVERHEAD,
        private bool $testParts = false,
        private Seconds|Absent $jobLimit = new Absent(),
        private Seconds|Absent $item = new Absent(),
    ) {
    }

    /** Shards of about `shards.seconds` each, each set up in `shards.setup`, at most `shards.max` of them. */
    public static function bySize(int $seconds, Seconds $setup, int $most): self
    {
        return new self(Seconds::of($seconds), $most, exact: false, target: Absent::setting(), overhead: $setup);
    }

    /** As many shards as fit a shard's setup and share of the cost into a target wall time, at most `shards.max`. */
    public static function toTarget(Seconds $target, Seconds $setup, int $most): self
    {
        return new self(Absent::setting(), $most, exact: false, target: $target, overhead: $setup);
    }

    /** Exactly this many shards, as `--shards=<n>` asks. */
    public static function exactly(int $count): self
    {
        return new self(Absent::setting(), $count, exact: true, target: Absent::setting(), overhead: Seconds::none());
    }

    /** This cut, at most this many shards, as a learned `shards.max` gives; a count asked for is cut as asked. */
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
                $this->testParts,
                $this->jobLimit,
                item: $this->item,
            );
    }

    /** This cut, making no shard whose share of the cost is below this many times its overhead: `shards.cutAt`. */
    public function cuttingAt(float $times): self
    {
        return new self(
            $this->size,
            $this->most,
            $this->exact,
            $this->target,
            $this->overhead,
            $times,
            $this->testParts,
            $this->jobLimit,
            item: $this->item,
        );
    }

    /** This cut, each of a package's shards taking every unit of it and its own part of the test files. */
    public function cuttingTestFiles(): self
    {
        return new self(
            $this->size,
            $this->most,
            $this->exact,
            $this->target,
            $this->overhead,
            $this->workPerOverhead,
            testParts: true,
            jobLimit: $this->jobLimit,
            item: $this->item,
        );
    }

    /** This cut, its shards no longer than this while the most there may be leaves room; a count asked for as asked. */
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
                $this->testParts,
                $limit,
                item: $this->item,
            );
    }

    /** This cut, where each shard's runner first spends this long on its opening run. */
    public function opening(Seconds $run): self
    {
        return new self(
            $this->size,
            $this->most,
            $this->exact,
            $this->target,
            Seconds::of($this->overhead->seconds() + $run->seconds()),
            $this->workPerOverhead,
            $this->testParts,
            $this->jobLimit,
            item: $this->item,
        );
    }

    /**
     * This cut, its shards pulling their work (ADR-0134): each package's work
     * cut into items of about this long, or under test parts into as many
     * parts, each item listed first for one of the package's shards.
     */
    public function pulling(Seconds $item): self
    {
        return new self(
            $this->size,
            $this->most,
            $this->exact,
            $this->target,
            $this->overhead,
            $this->workPerOverhead,
            $this->testParts,
            $this->jobLimit,
            item: $item,
        );
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
        $owners = [];
        $workers = 0;

        foreach ($this->countsFor($packages) as $at => $count) {
            $items = $this->itemsOf($packages[$at], $count);
            $made = $this->testParts ? $packages[$at]->parts($items) : $packages[$at]->runs($items);
            $runs[] = $made;
            $owners = [...$owners, ...$this->owners(count($made), max(1, $count), $workers)];
            $workers += max(1, $count);
        }

        $cut = array_merge(...$runs);

        $owners = $this->exact ? $this->owners(count($cut), min($this->most, max(1, count($cut))), 0) : $owners;

        return $this->item instanceof Seconds
            ? $this->pulled(Labels::of($trees, ...$cut), $owners)
            : Labels::of($trees, ...$this->exact ? array_pad($cut, $this->most, Run::of()) : $cut);
    }

    /**
     * How many runs a package is cut into: its shards, or where they pull,
     * as many items of the item's length as its cost takes, at least one a shard.
     */
    private function itemsOf(PackageWork $package, int $count): int
    {
        $item = $this->item instanceof Seconds ? $this->item->seconds() : 0.0;

        return $item > 0.0 ? max($count, (int) ceil($package->cost()->seconds() / $item), 1) : $count;
    }

    /**
     * The shard each of these items is listed first for, consecutive items to
     * one shard, the shards numbered after those before; a count asked for
     * spreads every item over its shards, since an item of any package may
     * run in any shard that pulls.
     *
     * @return list<int>
     */
    private function owners(int $items, int $count, int $before): array
    {
        $owners = [];

        for ($at = 0; $at < $items; $at++) {
            $owners[] = $before + intdiv($at * $count, $items) + 1;
        }

        return $owners;
    }

    /**
     * These items, each listed first for its shard.
     *
     * @param list<int> $owners
     */
    private function pulled(Shards $items, array $owners): Shards
    {
        $pulled = [];

        foreach ($items as $at => $item) {
            $pulled[] = $item->workedBy(ShardId::of(array_key_exists($at, $owners) ? $owners[$at] : 1));
        }

        return Shards::of(...$pulled);
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
