<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

use function array_key_exists;
use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function rtrim;
use function sprintf;

/**
 * The mutators a run leaves out of the files whose content is unchanged
 * (ADR-0025, decision 1), as the list beside the run's results that the gate
 * writes and a patched runner reads, in its own process, before it makes a
 * mutant. It is a JSON list of two lists: the mutators, by the runner's names
 * for them, and the files, as the runner finds them, from the project's root.
 * A list that cannot be read leaves nothing out: the runner makes every
 * mutant, and the gate carries no result over one it ran.
 */
final readonly class PrunedList
{
    /** Where the list holds the mutators, then the files: its first item, then its second. */
    private const int MUTATORS = 0;

    private const int FILES = 1;

    /** Where a run's list is written, beside its results. */
    public static function beside(string $results): string
    {
        return sprintf('%s.pruned', $results);
    }

    /** The list of what a run leaves out, its files from this root. */
    public static function text(Pruned $pruned, string $root): string
    {
        $files = [];

        foreach ($pruned->files() as $path) {
            $files[] = sprintf('%s/%s', rtrim($root, '/'), $path->value());
        }

        return Json::items(Json::items(...$pruned->mutators()), Json::items(...$files))->line();
    }

    /** What a list leaves out; nothing where there is no list, or it is not one. */
    public static function read(string|false $text): Pruned
    {
        $items = $text !== false ? self::itemsOf(Node::decode($text)) : [];

        return Pruned::of(
            MutatorNames::of(...array_key_exists(self::MUTATORS, $items) ? self::textsIn($items[self::MUTATORS]) : []),
            Paths::of(...array_map(
                Path::of(...),
                array_key_exists(self::FILES, $items) ? self::textsIn($items[self::FILES]) : [],
            )),
        );
    }

    /** @return list<Node> the items of a list; none where it is no list */
    private static function itemsOf(Node $list): array
    {
        try {
            return $list->items();
        } catch (NotInShape) {
            return [];
        }
    }

    /** @return list<string> the strings a list holds; none where it holds none */
    private static function textsIn(Node $list): array
    {
        $texts = [];

        foreach (self::itemsOf($list) as $item) {
            try {
                $texts[] = $item->text();
            } catch (NotInShape) {
                continue;
            }
        }

        return $texts;
    }
}
