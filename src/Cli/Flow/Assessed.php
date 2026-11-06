<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * A plan's results judged, before anything is reported or recorded: the
 * verdict, the committed baseline it was judged against, the ledgers it was
 * judged from, how many of the results it took
 * from a proof came from the run's own scope, whether a CI run must
 * refuse it for a tree held to no floor, and the coverage map it read, which
 * names the tests each proof records (ADR-0108).
 *
 * @internal
 */
final readonly class Assessed
{
    public function __construct(
        public Verdict $verdict,
        public Baseline $baseline,
        public Ledgers $ledgers,
        public int $ownScopeProofs,
        public bool $refused,
        public CoverageMap|CannotJudge $map,
    ) {
    }
}
