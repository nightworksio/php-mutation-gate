<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

/**
 * Where the coverage map a run measures against was kept, each case named as
 * the line that says how it was measured names it (ADR-0023, decision 2).
 */
enum KeptFrom: string
{
    /** The map the last round of `watch` left in the working tree it watches. */
    case LastRound = "the last round's map";

    /** The map the run's own scope keeps beside its ledger, other than the default branch's. */
    case OwnScope = "this scope's map";

    case DefaultBranch = "the default branch's map";

    /** Whether it was kept in a store, where only a clean working tree's map is kept. */
    public function isStored(): bool
    {
        return $this !== self::LastRound;
    }
}
