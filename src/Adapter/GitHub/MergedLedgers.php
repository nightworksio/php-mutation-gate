<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
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
 * judged (ADR-0139). Everything else is the other store's.
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

        return $read instanceof Ledger && $default instanceof Scope && $scope->equals($default)
            ? $read->withProofs($this->merged->carriedOnto($read))
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
