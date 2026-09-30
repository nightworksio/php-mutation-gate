<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

use function preg_match;
use function sprintf;

/**
 * A commit as git names it in full: 40 lowercase hex digits in a SHA-1
 * repository, 64 in a SHA-256 one. Read from a file the gate wrote, where
 * anything else, such as an option git would read, is refused.
 */
final readonly class Commit
{
    /** A full commit id, in either of git's object formats. */
    private const string ID = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D';

    private function __construct(private string $id)
    {
    }

    /** A commit as its full id spells it, or why it is none. */
    public static function parse(string $id): self|CannotTell
    {
        return preg_match(self::ID, $id) === 1
            ? new self($id)
            : CannotTell::because(sprintf('"%s" is not the full id of a commit.', $id));
    }

    /** The commit as a state of the repository. */
    public function revision(): Revision
    {
        return Revision::ref($this->id);
    }

    public function id(): string
    {
        return $this->id;
    }
}
