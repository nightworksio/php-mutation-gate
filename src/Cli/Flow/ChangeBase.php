<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a change-scoped run reads its change since: a ref, whose merge base
 * with HEAD the change is read from, and, for `last-run`, the commit its
 * scope's last run of the same kind judged, which the change is read from
 * itself (ADR-0005). The ref still gives the run's new code, and
 * every file changed since it carries only the run's own scope's results.
 * Off the default branch, the commit the default branch's ledger last passed
 * at: what changed since it reaches too, since that ledger's results are of
 * the code at it (ADR-0060).
 *
 * @internal
 */
final readonly class ChangeBase
{
    private function __construct(
        private Revision $since,
        private JudgedCommit|NotGiven $lastRun,
        private Revision|NotGiven $ledger,
    ) {
    }

    /** Since the merge base of this ref and HEAD. */
    public static function since(Revision $ref): self
    {
        return new self($ref, NotGiven::value(), NotGiven::value());
    }

    /** Since the commit a last run judged, with the ref its new code and its own scope's files are read since. */
    public static function lastRun(JudgedCommit $commit, Revision $ref): self
    {
        return new self($ref, $commit, NotGiven::value());
    }

    /** This base, with the commit the default branch's ledger last passed at, whose change since reaches too. */
    public function withLedgerAt(Revision $passed): self
    {
        return new self($this->since, $this->lastRun, $passed);
    }

    /** The commit the default branch's ledger last passed at; none on the default branch, or where none passed. */
    public function ledgerAt(): Revision|NotGiven
    {
        return $this->ledger;
    }

    /** The ref whose merge base with HEAD the new code is read from. */
    public function ref(): Revision
    {
        return $this->since;
    }

    /** The commit a last run judged, which the change is read from itself; none where it is read since the ref. */
    public function lastRunCommit(): JudgedCommit|NotGiven
    {
        return $this->lastRun;
    }
}
