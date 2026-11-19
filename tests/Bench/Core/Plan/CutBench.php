<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Bench\Core\Plan;

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function sprintf;

use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;

/** Cutting a plan's weighed units into shards, by size and to a target wall time: never worse than sorting them. */
final class CutBench
{
    /** @return iterable<string, array{int, Workload, Trees}> */
    public static function workloads(): iterable
    {
        foreach ([100, 400, 1600] as $units) {
            yield sprintf('units-%d', $units) => [
                $units,
                self::workload($units),
                Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root()))),
            ];
        }
    }

    #[DataProvider('workloads')]
    #[Linear('units')]
    #[Warmup(1)]
    public function benchBySize(int $units, Workload $work, Trees $trees): void
    {
        Cut::bySize(600, Seconds::of(30.0), 20)->cut($work, $trees);
    }

    #[DataProvider('workloads')]
    #[Linear('units')]
    #[Warmup(1)]
    public function benchToTarget(int $units, Workload $work, Trees $trees): void
    {
        Cut::toTarget(Seconds::of(900.0), Seconds::of(30.0), 40)->cut($work, $trees);
    }

    /** So many units in no path order, over thirteen directories, each of its own cost. */
    private static function workload(int $units): Workload
    {
        $weighed = [];

        for ($at = 0; $at < $units; $at++) {
            $weighed[] = Weighed::of(
                Unit::file(Path::of(sprintf('src/Part%d/Unit%05d.php', ($at * 7) % 13, ($at * 7919) % $units))),
                Package::at(Path::root()),
                Estimated::of(Seconds::of((float) (1 + ($at * 31) % 97)), CostBasis::Guessed),
            );
        }

        return Workload::of(...$weighed);
    }
}
