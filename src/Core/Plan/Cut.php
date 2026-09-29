<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function array_pad;
use function array_sum;
use function ceil;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * How units are cut into shards, in path order. By size, the number of
 * shards is each package's cost over `shards.seconds`, rounded up, and never
 * more than `shards.max` in all: past that, shards grow instead. Exactly, as
 * `--shards=<n>` asks, there are n shards, and those the units do not fill
 * are empty and say so. Packages never share a shard, because a shard runs in
 * one package's directory; floors may, because the verdict adds up each
 * mutant itself.
 */
final readonly class Cut
{
    /** The size of a shard where the count is fixed, which the workload decides rather than the config. */
    private const float UNSIZED = 0.0;

    private function __construct(private float $seconds, private int $most, private bool $exact)
    {
    }

    /** Shards of about `shards.seconds` each, at most `shards.max` of them. */
    public static function bySize(int $seconds, int $most): self
    {
        return new self($seconds, $most, exact: false);
    }

    /** Exactly this many shards, as `--shards=<n>` asks. */
    public static function exactly(int $count): self
    {
        return new self(self::UNSIZED, $count, exact: true);
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

        foreach ($this->countsFor($packages) as $package => $count) {
            $runs = [...$runs, ...Runs::into($packages[$package], $count)];
        }

        return Labels::of($this->exact ? array_pad($runs, $this->most, []) : $runs, $trees);
    }

    /**
     * How many shards each package is cut into. Where that comes to more than
     * the most there may be, every shard is made larger, until it fits or
     * every package has one shard.
     *
     * @param  array<string, list<Weighed>> $packages
     * @return array<string, int>
     */
    private function countsFor(array $packages): array
    {
        $size = $this->exact ? array_sum(array_map(Runs::costOf(...), $packages)) / $this->most : $this->seconds;
        $counts = $this->countsAt($packages, $size);

        while (array_sum($counts) > $this->most && array_sum($counts) > count($packages)) {
            $size = $size * array_sum($counts) / $this->most;
            $counts = $this->countsAt($packages, $size);
        }

        return $counts;
    }

    /**
     * @param  array<string, list<Weighed>> $packages
     * @return array<string, int>
     */
    private function countsAt(array $packages, float $size): array
    {
        return array_map(static function (array $units) use ($size): int {
            $cost = Runs::costOf($units);

            return $cost > 0.0 && $size > 0.0 ? (int) ceil($cost / $size) : 1;
        }, $packages);
    }
}
