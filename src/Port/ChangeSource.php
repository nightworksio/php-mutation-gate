<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * The repository as version control sees it. Where it cannot say, it answers
 * cannot tell, which the gate reads as "reach everything".
 */
interface ChangeSource
{
    /**
     * Every path changed from the merge base of this revision and HEAD to the
     * working tree, with its added and modified lines.
     */
    public function changesSince(Revision $base): Changes|CannotTell;

    /**
     * Every path changed from this revision itself to the working tree, with
     * its added and modified lines, whatever the revision's place in HEAD's
     * history.
     */
    public function changesFrom(Revision $commit): Changes|CannotTell;

    /** A commit as a run judged it: the tree it holds and the commits it was made from; or why git cannot say. */
    public function judged(Revision $commit): JudgedCommit|CannotTell;

    /**
     * A revision git can read for a commit a run judged: the commit itself,
     * or, where it is gone, the tree merging its two parents again gives,
     * only where that tree is the one the commit held; or why there is none,
     * so that what changed since it is read from elsewhere (ADR-0005,
     * decision 2).
     */
    public function readable(JudgedCommit $judged): Revision|CannotTell;

    /** Every file in the working tree that is not ignored, with the digest of what it holds. */
    public function fingerprints(): Fingerprints|CannotTell;

    /**
     * Every file whose content on disk is not what is staged: changed since
     * it was staged, or never added.
     */
    public function unstaged(): Paths|CannotTell;

    /**
     * When each of these paths last changed in a commit HEAD holds, a
     * directory when the newest of its files did. A path no commit changed
     * has none.
     *
     * @return ByPath<Instant>|CannotTell
     */
    public function lastChanged(Paths $paths): ByPath|CannotTell;

    /** What a file held at a revision. */
    public function fileAt(Path $path, Revision $revision): Contents|Missing|CannotTell;

    /**
     * What each of these files held at a revision, every one read at the same
     * commit.
     *
     * @return ByPath<Contents|Missing>|CannotTell
     */
    public function filesAt(Paths $paths, Revision $revision): ByPath|CannotTell;
}
