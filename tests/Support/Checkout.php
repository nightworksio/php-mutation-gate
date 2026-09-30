<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\Repository;

/** A change source and a repository answering as one version-control source, as git does. */
final readonly class Checkout implements ChangeSource, Repository
{
    private function __construct(private ChangeSource $changes, private Repository $repository)
    {
    }

    public static function of(ChangeSource $changes, Repository $repository): self
    {
        return new self($changes, $repository);
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        return $this->changes->changesSince($base);
    }

    public function fingerprints(): Fingerprints|CannotTell
    {
        return $this->changes->fingerprints();
    }

    public function fileAt(Path $path, Revision $revision): Contents|Missing|CannotTell
    {
        return $this->changes->fileAt($path, $revision);
    }

    public function head(): Revision|CannotTell
    {
        return $this->repository->head();
    }

    public function branch(): Scope|Detached|CannotTell
    {
        return $this->repository->branch();
    }

    public function defaultBranch(): Scope|CannotTell
    {
        return $this->repository->defaultBranch();
    }
}
