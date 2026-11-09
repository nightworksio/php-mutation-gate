<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * A plan's results judged, before anything is reported or recorded: the
 * verdict, the committed baseline it was judged against, the ledgers it was
 * judged from, the units whose results it took
 * from a proof of the run's own scope, whether a CI run must
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
        public Paths $ownScopeUnits,
        public bool $refused,
        public CoverageMap|CannotJudge $map,
    ) {
    }
}
