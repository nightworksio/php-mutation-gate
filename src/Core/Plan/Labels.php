<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_filter;
use function array_keys;
use function array_last;
use function array_map;
use function count;
use function implode;
use function in_array;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;
use function str_starts_with;
use function usort;

/**
 * Cut runs made into shards, numbered from 1 and labelled with the trees
 * each takes: a unit is of the deepest tree its path is under, and a unit
 * under no tree is named by its own path. A tree that spans several shards is
 * named with the part each takes, such as "src, part 2 of 4". An empty run is
 * an empty shard.
 */
final readonly class Labels
{
    /** @param list<list<Weighed>> $runs */
    public static function of(array $runs, Trees $trees): Shards
    {
        $named = array_map(static fn(array $run): array => self::treesOf($run, $trees), $runs);
        $shards = [];

        foreach ($runs as $at => $run) {
            $id = ShardId::of($at + 1);
            $shards[] = $run === [] ? Shard::empty($id) : Shard::of(
                $id,
                $run[0]->package(),
                Units::of(...array_map(static fn(Weighed $unit): Unit => $unit->unit(), $run)),
                Seconds::of(Runs::costOf($run)),
                self::labelOf($at, $named),
            );
        }

        return Shards::of(...$shards);
    }

    /**
     * @param  list<Weighed> $run
     * @return list<string>  the trees a run takes, in the order it takes them
     */
    private static function treesOf(array $run, Trees $trees): array
    {
        $taken = [];

        foreach ($run as $unit) {
            $tree = self::treeOf($unit->unit()->path(), $trees);
            $taken = in_array($tree, $taken, strict: true) ? $taken : [...$taken, $tree];
        }

        return $taken;
    }

    private static function treeOf(Path $unit, Trees $trees): string
    {
        $holding = array_filter(
            [...$trees],
            static fn(Tree $tree): bool => self::holds($tree, $unit),
        );

        if ($holding === []) {
            return $unit->value();
        }

        usort(
            $holding,
            static fn(Tree $one, Tree $other): int => self::depthOf($one) <=> self::depthOf($other),
        );

        return array_last($holding)->path()->value();
    }

    private static function depthOf(Tree $tree): int
    {
        return mb_strlen($tree->path()->value());
    }

    private static function holds(Tree $tree, Path $unit): bool
    {
        $root = $tree->path()->value();

        return $root === '.' || $unit->value() === $root || str_starts_with($unit->value(), sprintf('%s/', $root));
    }

    /** @param list<list<string>> $named each run's trees */
    private static function labelOf(int $at, array $named): string
    {
        $parts = [];

        foreach ($named[$at] as $tree) {
            $parts[] = self::partOf($tree, $at, $named);
        }

        return implode('; ', $parts);
    }

    /**
     * A tree as the label of one run names it, with the part of it that run
     * takes where the tree spans several.
     *
     * @param list<list<string>> $named each run's trees
     */
    private static function partOf(string $tree, int $at, array $named): string
    {
        $taking = array_filter($named, static fn(array $trees): bool => in_array($tree, $trees, strict: true));
        $spans = array_keys($taking);
        $part = count(array_filter($spans, static fn(int $span): bool => $span <= $at));

        return count($spans) === 1 ? $tree : sprintf('%s, part %d of %d', $tree, $part, count($spans));
    }
}
