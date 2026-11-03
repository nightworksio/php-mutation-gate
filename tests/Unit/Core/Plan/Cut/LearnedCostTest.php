<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Shards as ShardsConfig;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Tree as RepositoryTree;

/**
 * What the gate's own ledger learned of each unit, in path order, and the
 * opening run its plans measured.
 *
 * @return array{opening: float, seconds: list<float>}
 */
$learned = static function (): array {
    $read = Node::decode((string) file_get_contents(RepositoryTree::at('tests/Fixtures/Plan/learned-costs.json')));

    return [
        'opening' => $read->field('opening')->number(),
        'seconds' => array_map(static fn(Node $seconds): float => $seconds->number(), $read->field('seconds')->items()),
    ];
};

/** This many of the ledger's units, spread evenly over its path order, each weighed by what it learned. */
$reached = static function (int $count) use ($learned): Workload {
    $seconds = $learned()['seconds'];
    $weighed = [];

    foreach (range(0, $count - 1) as $at) {
        $weighed[] = Weighed::of(
            Unit::file(Path::of(sprintf('src/Unit%04d.php', $at))),
            Package::at(Path::root()),
            Estimated::of(Seconds::of($seconds[intdiv($at * count($seconds), $count)]), CostBasis::Learned),
        );
    }

    return Workload::of(...$weighed);
};

/** The cut the gate's own config asks for, `shards.max` and every other `shards` key at its default, opening as its plans do. */
$cut = static function () use ($learned): Cut {
    $config = ShardsConfig::standard();

    return Cut::bySize((int) $config->seconds()->seconds(), $config->setup(), $config->max())
        ->opening(Seconds::of($learned()['opening']));
};

/** @return list<int> each shard's estimated seconds, to the whole second */
$estimates = static fn(Shards $shards): array => array_map(
    static fn(Shard $shard): int => (int) round($shard->cost()->seconds()),
    iterator_to_array($shards, preserve_keys: false),
);

it('cuts the units a change reaches by what the ledger learned of them: one shard for few, more as their cost earns each its overhead, and the most for many', function (int $units, array $expected) use ($reached, $cut, $estimates): void {
    $shards = $cut()->cut($reached($units), Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root()))));

    expect($shards instanceof Shards ? [count($shards), $estimates($shards)] : $shards)->toBe([count($expected), $expected]);
})->with([
    '3 units, below twice a shard\'s overhead' => [3, [39]],
    '100 units, which two shards would leave each below twice its overhead' => [100, [975]],
    '2,000 units, more than the most shards hold at the size' => [2000, [957, 952, 870, 973, 975, 978, 952, 936, 966, 930, 809, 962, 889, 974, 975, 977, 924, 947, 978, 942]],
]);

it('reads its overhead as the gate does: the default setup and the opening run its plans measured', function () use ($learned): void {
    expect(ShardsConfig::standard()->setup())->toEqual(Seconds::minutes(1))
        ->and($learned()['opening'])->toBe(201.837)
        ->and(count($learned()['seconds']))->toBe(2225);
});
