<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\Repository;

final readonly class RepositoryFake implements Repository
{
    public function __construct(
        private Revision|CannotTell $head,
        private Scope|Detached|CannotTell $branch,
        private Scope|CannotTell $defaultBranch,
    ) {
    }

    /** A checkout on main, at this commit, whose remote calls main its default. */
    public static function onMain(Revision $head): self
    {
        return new self($head, Scope::branch('main'), Scope::branch('main'));
    }

    /** A checkout at this commit on a detached HEAD, with no remote to name a default branch. */
    public static function detachedAt(Revision $head): self
    {
        return new self(
            $head,
            Detached::head(),
            CannotTell::because('refs/remotes/origin/HEAD points at no branch, so git cannot name the default branch.'),
        );
    }

    public function head(): Revision|CannotTell
    {
        return $this->head;
    }

    public function branch(): Scope|Detached|CannotTell
    {
        return $this->branch;
    }

    public function defaultBranch(): Scope|CannotTell
    {
        return $this->defaultBranch;
    }
}
