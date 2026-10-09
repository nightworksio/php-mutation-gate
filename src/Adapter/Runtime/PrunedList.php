<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Runtime;

use function array_flip;
use function array_key_exists;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;
use function json_encode;
use function json_validate;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;

use function rtrim;
use function sprintf;

/**
 * The mutators a run leaves out of the files whose content is unchanged
 * (ADR-0025, decision 1), in a file beside the run's results: the gate
 * writes it, and a patched runner reads it once, in its own process, before
 * it makes a mutant, and makes none of those mutators in those files. It is
 * a JSON list of two lists: the mutators, by the runner's names for them,
 * and the files, as the runner finds them, from the project's root.
 * Where no list is named, or it cannot be read, the runner makes every
 * mutant, and the gate carries no result over one it ran.
 */
final class PrunedList
{
    /** Where the list holds the mutators, then the files: its first item, then its second. */
    private const int MUTATORS = 0;

    private const int FILES = 1;

    /** @var array<string, array{array<string, int>, array<string, int>}> each list read, by its file */
    private static array $read = [];

    /** Where a run's list is written, beside its results. */
    public static function beside(string $results): string
    {
        return sprintf('%s.pruned', $results);
    }

    /** Writes what a run leaves out to the file, its files from this root, and names the file. */
    public static function write(string $file, Pruned $pruned, string $root): string
    {
        $files = [];

        foreach ($pruned->files() as $path) {
            $files[] = sprintf('%s/%s', rtrim($root, '/'), $path->value());
        }

        file_put_contents($file, (string) json_encode([[...$pruned->mutators()], $files]));

        return $file;
    }

    /** Whether the list in this file leaves this mutator, by the runner's name for it, out of this file. */
    public static function leavesOut(string $list, string $file, string $mutator): bool
    {
        [$mutators, $files] = self::read($list);

        return array_key_exists($file, $files) && array_key_exists($mutator, $mutators);
    }

    /** @return array{array<string, int>, array<string, int>} the mutators and the files a list names, read once */
    private static function read(string $list): array
    {
        if (! array_key_exists($list, self::$read)) {
            $text = $list !== '' && is_file($list) ? file_get_contents($list) : false;
            $items = is_string($text) && json_validate($text) ? self::itemsOf(Node::decode($text)) : [];
            self::$read[$list] = [
                array_key_exists(self::MUTATORS, $items) ? self::namesIn($items[self::MUTATORS]) : [],
                array_key_exists(self::FILES, $items) ? self::namesIn($items[self::FILES]) : [],
            ];
        }

        return self::$read[$list];
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

    /** @return array<string, int> the strings a list holds, as keys; none where it holds none */
    private static function namesIn(Node $list): array
    {
        $names = [];

        foreach (self::itemsOf($list) as $name) {
            try {
                $names[] = $name->text();
            } catch (NotInShape) {
                continue;
            }
        }

        return array_flip($names);
    }
}
