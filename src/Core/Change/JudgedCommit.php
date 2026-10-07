<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

/**
 * A commit a run judged, with the tree it holds and the commits it was made
 * from, so that git can read what it held after the commit itself is gone:
 * as a pull request's merge commit is once a newer push replaces it, where
 * merging its two parents again gives the same tree (ADR-0005, decision 2).
 */
final readonly class JudgedCommit
{
    private function __construct(private Revision $commit, private Tree $tree, private Parents $parents)
    {
    }

    public static function of(Revision $commit, Tree $tree, Revision ...$parents): self
    {
        return new self($commit, $tree, Parents::of(...$parents));
    }

    public function commit(): Revision
    {
        return $this->commit;
    }

    /** The tree the commit holds. */
    public function tree(): Tree
    {
        return $this->tree;
    }

    /** The commits it was made from, in its order. */
    public function parents(): Parents
    {
        return $this->parents;
    }
}
