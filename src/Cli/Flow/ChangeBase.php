<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Paths;
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
        private Revision|NotGiven $openSince,
        private Paths $open,
    ) {
    }

    /** Since the merge base of this ref and HEAD. */
    public static function since(Revision $ref): self
    {
        return new self($ref, NotGiven::value(), NotGiven::value(), NotGiven::value(), Paths::none());
    }

    /** Since the commit a last run judged, with the ref its new code and its own scope's files are read since. */
    public static function lastRun(JudgedCommit $commit, Revision $ref): self
    {
        return new self($ref, $commit, NotGiven::value(), NotGiven::value(), Paths::none());
    }

    /**
     * This base, with the commit of the default branch's ledger its change since reaches too: where it last
     * passed, or its newest judged run (ADR-0141).
     */
    public function withLedgerAt(Revision $commit): self
    {
        return new self($this->since, $this->lastRun, $commit, $this->openSince, $this->open);
    }

    /**
     * This base, the units a judged run of the default branch left open also reached by what changed since its
     * last pass (ADR-0141).
     */
    public function withOpenSince(Revision $passed, Paths $open): self
    {
        return new self($this->since, $this->lastRun, $this->ledger, $passed, $open);
    }

    /** The commit the units a judged run left open read their change since; none where they read none apart. */
    public function openSince(): Revision|NotGiven
    {
        return $this->openSince;
    }

    /** The units a judged run of the default branch left open. */
    public function open(): Paths
    {
        return $this->open;
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
