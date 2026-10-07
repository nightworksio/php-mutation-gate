<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

/**
 * A state of the repository: a ref or commit as git names it, a tree by its
 * full id, as a merge rebuilt from a commit's parents leaves one, or the
 * working tree as it is on disk.
 */
final readonly class Revision
{
    /** The commit the checkout is at, as git names it, and as it names a detached checkout's branch. */
    public const string HEAD = 'HEAD';

    private const string WORKING_TREE = 'the working tree';

    private function __construct(private string $name, private bool $workingTree, private bool $tree = false)
    {
    }

    public static function ref(string $ref): self
    {
        return new self($ref, workingTree: false);
    }

    /** A tree, by its full id: the files a commit would hold, with no commit naming it. */
    public static function tree(Tree $tree): self
    {
        return new self($tree->id(), workingTree: false, tree: true);
    }

    /** The commit the checkout is at. */
    public static function head(): self
    {
        return self::ref(self::HEAD);
    }

    public static function workingTree(): self
    {
        return new self(self::WORKING_TREE, workingTree: true);
    }

    public function isWorkingTree(): bool
    {
        return $this->workingTree;
    }

    /** Whether this names a tree rather than a commit or ref. */
    public function isTree(): bool
    {
        return $this->tree;
    }

    /** The ref as git names it, or "the working tree". */
    public function name(): string
    {
        return $this->name;
    }
}
