<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a change-scoped run reads its change since: a ref, whose merge base
 * with HEAD the change is read from, and, for `last-run`, the commit its
 * scope's last run of the same kind judged, which the change is read from
 * itself (ADR-0005, decision 2). The ref still gives the run's new code, and
 * every file changed since it carries only the run's own scope's results.
 */
final readonly class ChangeBase
{
    private function __construct(private Revision $since, private Revision|NotGiven $lastRun)
    {
    }

    /** Since the merge base of this ref and HEAD. */
    public static function since(Revision $ref): self
    {
        return new self($ref, NotGiven::value());
    }

    /** Since the commit a last run judged, with the ref its new code and its own scope's files are read since. */
    public static function lastRun(Revision $commit, Revision $ref): self
    {
        return new self($ref, $commit);
    }

    /** The ref whose merge base with HEAD the new code is read from. */
    public function ref(): Revision
    {
        return $this->since;
    }

    /** The commit a last run judged, which the change is read from itself; none where it is read since the ref. */
    public function lastRunCommit(): Revision|NotGiven
    {
        return $this->lastRun;
    }
}
