<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * What changed from a commit or tree to what is on disk, as git diffs it,
 * with renames found, and every file git neither tracks nor ignores as added
 * whole, but those in the gate's own workspace (see Untracked).
 */
final readonly class Changed
{
    /** What changed from the object with this id, read from git and the working tree. */
    public static function from(Command $git, WorkingTree $tree, string $id): Changes|CannotTell
    {
        $printed = self::printed($git, [
            ['diff', '--relative', '--name-status', '-z', $id],
            ['diff', '--no-ext-diff', '--relative', '--unified=0', $id],
            Untracked::command(),
        ]);

        if ($printed instanceof CannotTell) {
            return $printed;
        }

        [$status, $patch, $untracked] = $printed;

        return self::withUntracked(Diff::changes($status, Diff::lines($patch)), $untracked, $tree);
    }

    /**
     * What git printed for each of these, or why it could not answer one.
     *
     * @param  list<list<string>> $commands
     * @return list<string>|CannotTell
     */
    private static function printed(Command $git, array $commands): array|CannotTell
    {
        $printed = [];

        foreach ($commands as $arguments) {
            $output = $git->run($arguments);

            if ($output instanceof CannotTell) {
                return $output;
            }

            $printed[] = $output;
        }

        return $printed;
    }

    private static function withUntracked(Changes $changes, string $untracked, WorkingTree $tree): Changes
    {
        $added = [];

        foreach (Diff::paths($untracked) as $path) {
            $read = $tree->read(Path::of($path));
            $lines = $read instanceof Contents ? Diff::whole($read->text()) : Lines::none();
            $added[] = Change::added(Path::of($path), $lines);
        }

        return Changes::of(...$changes, ...$added);
    }
}
