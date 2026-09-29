<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The repository as version control sees it. Where it cannot say, it answers
 * cannot tell, which the gate reads as "reach everything".
 */
interface ChangeSource
{
    /** Every path changed from the merge base of this revision and HEAD to the working tree, with its added and modified lines. */
    public function changesSince(Revision $base): Changes|CannotTell;

    /** Every file in the working tree that is not ignored, with the digest of what it holds. */
    public function fingerprints(): Fingerprints|CannotTell;

    /** What a file held at a revision. */
    public function fileAt(Path $path, Revision $revision): Contents|Missing|CannotTell;
}
