<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * A plan's results judged, before anything is reported or recorded: the
 * verdict, the committed baseline it was judged against, the ledgers it was
 * judged from, how many of the results it took
 * from a proof came from the run's own scope, and whether a CI run must
 * refuse it for a tree held to no floor.
 */
final readonly class Assessed
{
    public function __construct(
        public Verdict $verdict,
        public Baseline $baseline,
        public Ledgers $ledgers,
        public int $ownScopeProofs,
        public bool $refused,
    ) {
    }
}
