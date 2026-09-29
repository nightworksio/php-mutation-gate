<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;

/**
 * Where ledgers are kept between runs: a directory, a bucket. An unreadable or
 * unwritten ledger costs a run later, never a verdict.
 */
interface ProofStore
{
    /** The ledger of a scope, keeping each well-formed entry and dropping the rest; empty where there is none. */
    public function read(Scope $scope): Ledger;

    /** Keep this ledger as the scope's own, replacing what was there. */
    public function write(Scope $scope, Ledger $ledger): Written|NotWritten;
}
