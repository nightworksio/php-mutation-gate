<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_get_contents;
use function file_put_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\PrunedList;

/**
 * The file of the mutators a run leaves out of its unchanged files (see
 * PrunedList): the gate writes it beside the run's results, and the patched plugin
 * reads it once, in its own process, before it makes a mutant.
 */
final class PrunedFile
{
    /** @var array<string, Pruned> each list read, by its file */
    private static array $read = [];

    /** Writes what a run leaves out to the file, its files from this root, and names the file. */
    public static function write(string $file, Pruned $pruned, string $root): string
    {
        file_put_contents($file, PrunedList::text($pruned, $root));

        return $file;
    }

    /** Whether the list in this file leaves this mutator, by the runner's name for it, out of this file. */
    public static function leavesOut(string $list, string $file, string $mutator): bool
    {
        self::$read[$list] ??= PrunedList::read(is_file($list) ? file_get_contents($list) : false);

        return self::$read[$list]->leavesOut(Path::of($file), RunnerMutatorName::of($mutator));
    }
}
