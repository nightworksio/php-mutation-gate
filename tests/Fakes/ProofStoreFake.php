<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

/** A proof store that keeps each scope's ledger in memory. */
final class ProofStoreFake implements ProofStore
{
    /** @var array<string, Ledger> by scope */
    private array $ledgers = [];

    public function read(Scope $scope): Ledger
    {
        return array_key_exists($scope->ref(), $this->ledgers) ? $this->ledgers[$scope->ref()] : Ledger::empty();
    }

    public function write(Scope $scope, Ledger $ledger): Written
    {
        $this->ledgers[$scope->ref()] = $ledger;

        return Written::to(sprintf('memory:%s', $scope->ref()));
    }
}
