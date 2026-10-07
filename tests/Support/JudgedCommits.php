<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function hash;

use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Change\Tree;
use RuntimeException;

/** Commits as a run judged them, for tests: each with a tree named by its own name, and the parents given. */
final class JudgedCommits
{
    /** A commit by this name, holding the tree its name gives, made from these. */
    public static function of(string $commit, string ...$parents): JudgedCommit
    {
        return JudgedCommit::of(
            Revision::ref($commit),
            self::treeOf($commit),
            ...array_map(Revision::ref(...), $parents),
        );
    }

    /** The tree a commit by this name holds, for tests. */
    public static function treeOf(string $commit): Tree
    {
        $tree = Tree::parse(hash('sha256', $commit));

        return $tree instanceof Tree ? $tree : throw new RuntimeException($tree->why());
    }
}
