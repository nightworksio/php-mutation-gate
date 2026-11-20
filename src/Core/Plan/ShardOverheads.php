<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function array_sum;
use function ceil;
use function floor;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a shard's overhead, its setup and opening run, makes worth cutting: no
 * shard whose share of its package's cost is below `shards.cutAt` times it
 * (ADR-0013, ADR-0114), and, where the minutes are billed, the count whose
 * jobs bill the fewest whole minutes (ADR-0240).
 *
 * @internal
 */
final readonly class ShardOverheads
{
    /** @param float $scale how many seconds of the cost a second of a shard's wall time holds */
    private function __construct(private Seconds $overhead, private float $workPerOverhead, private float $scale)
    {
    }

    public static function of(Seconds $overhead, float $workPerOverhead, float $scale): self
    {
        return new self($overhead, $workPerOverhead, $scale);
    }

    /**
     * These counts, each lowered to the most shards whose share of the
     * package's cost is at least `shards.cutAt` times their overhead. A
     * package lowered to none still runs, in one shard, as each package does.
     *
     * @param  list<PackageWork> $packages
     * @param  list<int>         $counts
     * @return list<int>
     */
    public function worthCutting(array $packages, array $counts): array
    {
        return array_map(
            fn(PackageWork $package, int $count): int => min($count, $this->mostWorth($package, $count)),
            $packages,
            $counts,
        );
    }

    /**
     * These counts, each raised to the one at which its package's jobs bill
     * the fewest whole minutes, while the most there may be and `shards.cutAt`
     * leave room, the packages in order; a tie goes to more shards.
     *
     * @param  list<PackageWork> $packages
     * @param  list<int>         $counts
     * @return list<int>
     */
    public function fewestMinutes(array $packages, array $counts, int $most): array
    {
        $room = max(0, $most - array_sum($counts));
        $fewest = [];

        foreach ($packages as $at => $package) {
            $fits = max(1, $counts[$at]);
            $best = $fits;
            $last = min($fits + $room, $this->mostWorth($package, $fits + $room));

            for ($count = $fits + 1; $count <= $last; $count++) {
                $best = $this->billedAt($package, $count) <= $this->billedAt($package, $best) ? $count : $best;
            }

            $room -= $best - $fits;
            $fewest[] = $best === $fits ? $counts[$at] : $best;
        }

        return $fewest;
    }

    /**
     * The most shards whose share of the package's cost is at least
     * `shards.cutAt` times their overhead; this many where a shard has none.
     */
    private function mostWorth(PackageWork $package, int $count): int
    {
        $least = $this->overhead->seconds() * $this->workPerOverhead * $this->scale;

        return $least > 0.0 ? (int) floor($package->cost()->seconds() / $least) : $count;
    }

    /** The minutes a package's jobs bill, cut into this many shards, each job's time rounded up to a whole minute. */
    private function billedAt(PackageWork $package, int $count): int
    {
        $job = $this->overhead->seconds() + $package->cost()->seconds() / $count / $this->scale;

        return $count * (int) ceil($job / Seconds::PER_MINUTE);
    }
}
