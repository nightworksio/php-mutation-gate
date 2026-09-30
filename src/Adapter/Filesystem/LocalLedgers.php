<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * The proofs a run outside CI keeps (ADR-0010, decision 4): its own ledgers
 * in a directory, read together with the store the config names, which it
 * never writes. What a machine judged stays on it, and what CI proved still
 * completes each tree (ADR-0015, decision 11).
 */
final readonly class LocalLedgers implements ProofStore
{
    private function __construct(private LedgerDirectory $local, private ProofStore $shared)
    {
    }

    /** `.mutation-gate/ledger` over the store the config names. */
    public static function over(ProofStore $shared): self
    {
        return self::of(LedgerDirectory::at(Workspace::ledger()->value()), $shared);
    }

    /** These local ledgers over this store. */
    public static function of(LedgerDirectory $local, ProofStore $shared): self
    {
        return new self($local, $shared);
    }

    /**
     * A scope's local ledger, and the shared store's after it; where one cannot be read, why, with what the other
     * holds beside it; where neither can, why for each, the shared store's first.
     */
    public function read(Scope $scope): Ledger|Unreadable
    {
        $local = $this->local->read($scope);
        $shared = $this->shared->read($scope);

        return match (true) {
            $shared instanceof Unreadable && $local instanceof Unreadable => $shared->also($local),
            $shared instanceof Unreadable => $shared->besides($local),
            $local instanceof Unreadable => $local->besides($shared),
            default => $local->and($shared),
        };
    }

    /** The scope's local ledger; the shared store is never written. */
    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        return $this->local->write($scope, $ledger);
    }
}
