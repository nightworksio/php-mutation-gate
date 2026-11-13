<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function array_merge;
use function array_pad;
use function ceil;
use function count;
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
    /** @param Seconds|Absent $item the length of an item each package's work is cut into, where its shards pull */
    private function __construct(
        private ShardSizing $sizing,
        private bool $testParts = false,
        private Seconds|Absent $item = new Absent(),
    ) {
    }

    /** Shards of about `shards.seconds` each, each set up in `shards.setup`, at most `shards.max` of them. */
    public static function bySize(int $seconds, Seconds $setup, int $most): self
    {
        return new self(ShardSizing::bySize(Seconds::of($seconds), $setup, $most));
    }

    /** As many shards as fit a shard's setup and share of the cost into a target wall time, at most `shards.max`. */
    public static function toTarget(Seconds $target, Seconds $setup, int $most): self
    {
        return new self(ShardSizing::toTarget($target, $setup, $most));
    }

    /** Exactly this many shards, as `--shards=<n>` asks. */
    public static function exactly(int $count): self
    {
        return new self(ShardSizing::exactly($count));
    }

    /** This cut, at most this many shards, as a learned `shards.max` gives; a count asked for is cut as asked. */
    public function atMost(int $most): self
    {
        return new self($this->sizing->atMost($most), $this->testParts, $this->item);
    }

    /** This cut, making no shard whose share of the cost is below this many times its overhead: `shards.cutAt`. */
    public function cuttingAt(float $times): self
    {
        return new self($this->sizing->cuttingAt($times), $this->testParts, $this->item);
    }

    /** This cut, each of a package's shards taking every unit of it and its own part of the test files. */
    public function cuttingTestFiles(): self
    {
        return new self($this->sizing, testParts: true, item: $this->item);
    }

    /** This cut, its shards no longer than this while the most there may be leaves room; a count asked for as asked. */
    public function withinJobLimit(Seconds $limit): self
    {
        return new self($this->sizing->withinJobLimit($limit), $this->testParts, $this->item);
    }

    /** This cut, where each shard's runner first spends this long on its opening run. */
    public function opening(Seconds $run): self
    {
        return new self($this->sizing->opening($run), $this->testParts, $this->item);
    }

    /**
     * This cut, its shards pulling their work (ADR-0134): each package's work
     * cut into items of about this long, or under test parts into as many
     * parts, each item listed first for one of the package's shards.
     */
    public function pulling(Seconds $item): self
    {
        return new self($this->sizing, $this->testParts, $item);
    }

    public function cut(Workload $work, Trees $trees): Shards|CannotJudge
    {
        $packages = $work->byPackage();

        return $this->sizing->exact() && count($packages) > $this->sizing->most()
            ? CannotJudge::because(sprintf(
                '--shards=%d cannot hold %d packages, because packages never share a shard. Ask for %d shards or more.',
                $this->sizing->most(),
                count($packages),
                count($packages),
            ))
            : $this->made($packages, $trees);
    }

    /** @param list<PackageWork> $packages */
    private function made(array $packages, Trees $trees): Shards
    {
        $runs = [];
        $owners = [];
        $workers = 0;

        foreach ($this->sizing->counts($packages) as $at => $count) {
            $made = $this->runsOf($packages[$at], $count);
            $runs[] = $made;
            $owners = [...$owners, ...$this->owners(count($made), max(1, $count), $workers)];
            $workers += max(1, $count);
        }

        $cut = array_merge(...$runs);

        return $this->item instanceof Seconds
            ? $this->pulled(Labels::of($trees, ...$cut), $this->sizing->exact() ? $this->spread(count($cut)) : $owners)
            : Labels::of($trees, ...$this->sizing->exact() ? array_pad($cut, $this->sizing->most(), Run::of()) : $cut);
    }

    /** @return list<Run> */
    private function runsOf(PackageWork $package, int $count): array
    {
        $items = $this->itemsOf($package, $count);

        return $this->testParts ? $package->parts($items) : $package->runs($items);
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
     * The shard each of these items is listed first for, where the count was
     * asked for: every item spread over the shards, since an item of any
     * package may run in any shard that pulls.
     *
     * @return list<int>
     */
    private function spread(int $items): array
    {
        return $this->owners($items, min($this->sizing->most(), max(1, $items)), 0);
    }

    /**
     * The shard each of these items is listed first for, consecutive items to
     * one shard, the shards numbered after those before.
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
}
