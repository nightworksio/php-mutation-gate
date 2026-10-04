<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\StaticChecker;

/**
 * A static analyser ready to check a shard's survivors: what it said it is,
 * what it found in the originals, against which each mutant's findings are
 * read, how long that run took, which stands for a check's time until
 * one is measured, and where each mutant's dependents are found.
 */
final readonly class WarmedUp
{
    public function __construct(
        public StaticChecker $checker,
        public AnalyserIdentity $identity,
        public Findings $findings,
        public Seconds $took,
        public Dependents $dependents,
    ) {
    }
}
