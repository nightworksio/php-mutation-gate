<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function range;
use function sprintf;

/**
 * Plans made on commit 5eeca8f whose shard n mutates src/<n>.php, is
 * labelled "src, part n of m", and is expected to take n minutes and a half
 * second.
 */
final class ShardedPlan
{
    public const string COMMIT = '5eeca8f';

    public static function of(int $shards): Plan
    {
        return Plan::of(Revision::ref(self::COMMIT), Keys::none(), Shards::of(...array_map(
            static fn(int $id): Shard => Shard::of(
                ShardId::of($id),
                Package::at(Path::root()),
                Units::of(Unit::file(Path::of(sprintf('src/%d.php', $id)))),
                Seconds::of($id * 60.0 + 0.5),
                sprintf('src, part %d of %d', $id, $shards),
            ),
            $shards === 0 ? [] : range(1, $shards),
        )));
    }
}
