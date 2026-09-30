<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

/** A proof store that keeps each scope's ledger in memory; or one that reads none, and says why. */
final class ProofStoreFake implements ProofStore
{
    /** @var array<string, Ledger> by scope */
    private array $ledgers = [];

    private Unreadable|NotGiven $unread;

    public function __construct()
    {
        $this->unread = NotGiven::value();
    }

    /** A store that reads no ledger, for this reason. */
    public static function unreadable(Unreadable $unread): self
    {
        $store = new self();
        $store->unread = $unread;

        return $store;
    }

    public function read(Scope $scope): Ledger|Unreadable
    {
        return match (true) {
            $this->unread instanceof Unreadable => $this->unread,
            array_key_exists($scope->ref(), $this->ledgers) => $this->ledgers[$scope->ref()],
            default => Ledger::empty(),
        };
    }

    public function write(Scope $scope, Ledger $ledger): Written
    {
        $this->ledgers[$scope->ref()] = $ledger;

        return Written::to(sprintf('memory:%s', $scope->ref()));
    }
}
