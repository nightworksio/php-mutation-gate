<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;

/**
 * Where ledgers, and the objects kept beside them, are kept between runs: a
 * directory, a bucket. An unreadable or unwritten ledger or object costs a
 * run later, never a verdict, and the run says why.
 */
interface ProofStore
{
    /**
     * The ledger of a scope, keeping each well-formed entry and dropping the rest; empty where there is none yet;
     * or why one that may be there could not be read.
     */
    public function read(Scope $scope): Ledger|Unreadable;

    /** Keep this ledger as the scope's own, replacing what was there. */
    public function write(Scope $scope, Ledger $ledger): Written|NotWritten;

    /**
     * The bytes of an object kept beside a scope's ledger, read within that
     * object's limits; none where there is none yet; or why one that may be
     * there could not be read.
     */
    public function companion(Scope $scope, Companion $companion): Contents|Missing|CannotJudge;

    /** Keep these bytes as an object beside the scope's own ledger, replacing what was there. */
    public function keep(Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten;
}
