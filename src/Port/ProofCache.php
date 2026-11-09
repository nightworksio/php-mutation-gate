<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The store of `outbreak cache` (ADR-0131), reached by Outbreak's binary:
 * the packs a run trusts pulled into the gate's directory, and the entries a
 * verdict accepted merged into their class's pack. Whatever it answers, a run
 * proves from its ledgers at least: a pack not pulled proves nothing, and a
 * pack not pushed costs a later run, never a verdict.
 */
interface ProofCache
{
    /** Each pack the request names pulled into its path; or why none was. */
    public function pulled(CacheRequest $request): Written|NotWritten;

    /** The entries at each path the request names merged into its class's pack in the store; or why they were not. */
    public function pushed(CacheRequest $request): Written|NotWritten;
}
