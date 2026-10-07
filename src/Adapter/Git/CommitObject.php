<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function explode;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Change\Tree;

use function sprintf;
use function str_starts_with;

/**
 * A commit as `git cat-file commit` prints it: its headers, up to the first
 * blank line, name its tree and each of its parents; its message follows.
 */
final readonly class CommitObject
{
    private const string TREE = 'tree ';

    private const string PARENT = 'parent ';

    private const string NO_TREE = 'Git printed no tree for %s.';

    /** The commit with this id, as git printed it. */
    public static function read(string $id, string $printed): JudgedCommit|CannotTell
    {
        $tree = CannotTell::because(sprintf(self::NO_TREE, $id));
        $parents = [];

        foreach (explode("\n", $printed) as $line) {
            if ($line === '') {
                break;
            }

            $tree = str_starts_with($line, self::TREE) ? Tree::parse(self::after(self::TREE, $line)) : $tree;
            $parent = str_starts_with($line, self::PARENT) ? Commit::parse(self::after(self::PARENT, $line)) : null;
            $parents = $parent instanceof Commit ? [...$parents, $parent->revision()] : $parents;
        }

        return $tree instanceof Tree ? JudgedCommit::of(Revision::ref($id), $tree, ...$parents) : $tree;
    }

    /** What a header line holds after its name. */
    private static function after(string $name, string $line): string
    {
        return mb_substr($line, mb_strlen($name));
    }
}
