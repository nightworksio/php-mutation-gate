<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Proof\Scope;

/**
 * Where the checkout stands in version control: the commit it is at, the
 * branch it is on, and the branch the remote calls its default.
 */
interface Repository
{
    /** The commit the checkout is at, by its full name. */
    public function head(): Revision|CannotTell;

    /** The branch the checkout is on, as its scope, or none on a detached `HEAD`. */
    public function branch(): Scope|Detached|CannotTell;

    /** The branch `refs/remotes/origin/HEAD` points at, as its scope. */
    public function defaultBranch(): Scope|CannotTell;
}
