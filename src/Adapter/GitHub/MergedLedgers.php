<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * Another store, whose default branch's ledger is read with the results the pull requests it merged carry, as
 * {@see MergedHeads} trusts them, so a unit the push does not reach carries the result its pull request's verdict
 * judged (ADR-0139), or, where the push is judged, lent, so they prove a unit only where their key matches
 * (ADR-0198). Everything else is the other store's.
 *
 * @internal
 */
final readonly class MergedLedgers implements ProofStore
{
    private function __construct(private ProofStore $store, private PassedPullRequests $merged)
    {
    }

    public static function over(ProofStore $store, PassedPullRequests $merged): self
    {
        return new self($store, $merged);
    }

    public function read(Scope $scope): Ledger|Unreadable
    {
        $read = $this->store->read($scope);
        $default = $this->merged->defaultBranch();

        $merged = $read instanceof Ledger && $default instanceof Scope && $scope->equals($default)
            ? $this->merged->mergedOnto($read)
            : NotGiven::value();

        return $read instanceof Ledger && $merged instanceof MergedRange
            ? $read->withProofs($merged->carried())->lending($merged->lent())
            : $read;
    }

    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        return $this->store->write($scope, $ledger);
    }

    public function companion(Scope $scope, Companion $companion): Contents|Missing|CannotJudge
    {
        return $this->store->companion($scope, $companion);
    }

    public function keep(Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten
    {
        return $this->store->keep($scope, $companion, $bytes);
    }
}
