<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Copies;
use NightWorksIO\MutationGate\Core\Proof\EncodesLedgers;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * The store a run keeps its ledgers in, read beside the copy of a scope's
 * ledger that `fetch` wrote from the proof store, whichever kind it is; where
 * both are there, `proofs.copies` chooses what the run reads. Only the store
 * the run keeps its ledgers in is written.
 *
 * @internal
 */
final readonly class FetchedCopies implements EncodesLedgers, ProofStore
{
    private function __construct(private ProofStore $kept, private LedgerDirectory $fetched, private Copies $copies)
    {
    }

    /** The copies `fetch` writes into the project's `.mutation-gate/fetched`, beside this store. */
    public static function over(ProofStore $kept, Copies $copies, Root $project): self
    {
        return self::of($kept, LedgerDirectory::in($project, Workspace::fetched()->value()), $copies);
    }

    /** The copies under this directory, beside this store. */
    public function encoding(LedgerCodec $codec): static
    {
        return new self(
            $this->kept instanceof EncodesLedgers ? $this->kept->encoding($codec) : $this->kept,
            $this->fetched->encoding($codec),
            $this->copies,
        );
    }

    public function limitedTo(LedgerLimits $limits): static
    {
        return new self(
            $this->kept instanceof EncodesLedgers ? $this->kept->limitedTo($limits) : $this->kept,
            $this->fetched->limitedTo($limits),
            $this->copies,
        );
    }

    public static function of(ProofStore $kept, LedgerDirectory $fetched, Copies $copies): self
    {
        return new self($kept, $fetched, $copies);
    }

    /**
     * The scope's ledger as `proofs.copies` chooses it of the two copies; the
     * kept one where no copy was fetched; where one cannot be read, why, with
     * the other beside it; where neither can, why for each, the fetched first.
     */
    public function read(Scope $scope): Ledger|Unreadable
    {
        $kept = $this->kept->read($scope);
        $fetched = $this->fetched->held($scope);

        return match (true) {
            $fetched instanceof Missing => $kept,
            $fetched instanceof Unreadable && $kept instanceof Unreadable => $fetched->also($kept),
            $fetched instanceof Unreadable => $fetched->besides($kept),
            $kept instanceof Unreadable => $kept->besides($fetched),
            default => $this->copies->chosen($kept, $fetched),
        };
    }

    public function companion(Scope $scope, Companion $companion): Contents|Missing|CannotJudge
    {
        return $this->kept->companion($scope, $companion);
    }

    public function keep(Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten
    {
        return $this->kept->keep($scope, $companion, $bytes);
    }

    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        return $this->kept->write($scope, $ledger);
    }
}
