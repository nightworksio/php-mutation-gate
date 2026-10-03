<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Push;

use NightWorksIO\MutationGate\Core\Change\Revision;

use function preg_match;

/**
 * One ref git is about to push, as it hands the line to the `pre-push` hook:
 * the local ref and the commit it pushes, and the commit the remote holds at
 * the ref it pushes to. Git names no commit, on either side, by zeros.
 */
final readonly class PushedRef
{
    /** How git names no commit: zeros, as long as its object names. */
    private const string NO_COMMIT = '#^0+$#';

    private function __construct(private string $localRef, private Revision $local, private Revision $remote)
    {
    }

    public static function of(string $localRef, string $local, string $remote): self
    {
        return new self($localRef, Revision::ref($local), Revision::ref($remote));
    }

    /** The commit the push sends. */
    public function local(): Revision
    {
        return $this->local;
    }

    /** The local ref, as git names it. */
    public function localRef(): string
    {
        return $this->localRef;
    }

    /** Whether the push deletes the remote ref, which sends no commit. */
    public function deletes(): bool
    {
        return $this->isNoCommit($this->local);
    }

    /**
     * What the change is read since: the commit the remote holds, or, for a
     * ref the remote does not hold yet, the default branch, whose merge base
     * the change is read from (ADR-0010, decision 2).
     */
    public function base(Revision $defaultBranch): Revision
    {
        return $this->isNoCommit($this->remote) ? $defaultBranch : $this->remote;
    }

    private function isNoCommit(Revision $revision): bool
    {
        return preg_match(self::NO_COMMIT, $revision->name()) === 1;
    }
}
