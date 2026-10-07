<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

use function sprintf;

/**
 * A tree as git names it in full, in either of its object formats: the files
 * of a commit, without the commit. Read from a file the gate wrote, where
 * anything else, such as an option git would read, is refused.
 */
final readonly class Tree
{
    private function __construct(private string $id)
    {
    }

    /** A tree as its full id spells it, or why it is none. */
    public static function parse(string $id): self|CannotTell
    {
        return Commit::parse($id) instanceof Commit
            ? new self($id)
            : CannotTell::because(sprintf('"%s" is not the full id of a tree.', $id));
    }

    public function id(): string
    {
        return $this->id;
    }
}
