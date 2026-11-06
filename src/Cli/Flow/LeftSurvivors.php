<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

/**
 * What a round of static analysis's checks has left so far: the checks,
 * with each survivor left unchecked and why, and the survivors among them a
 * check that never ran may yet kill, which doom no run (ADR-0103).
 *
 * @internal
 */
final readonly class LeftSurvivors
{
    private function __construct(public SurvivorChecks $checks, public MutantIds $unsettled)
    {
    }

    public static function none(): self
    {
        return new self(SurvivorChecks::none(), MutantIds::none());
    }

    /** These, with a survivor left unchecked. */
    public function leaving(UncheckedSurvivor $left, MutantId $survivor): self
    {
        return new self(
            $this->checks->leaving($left),
            $left->why()->settles() ? $this->unsettled : $this->unsettled->and(MutantIds::of($survivor)),
        );
    }

    /** These, with what the checks taught of one analyser. */
    public function timing(AnalyserHistory $history): self
    {
        return new self($this->checks->timing($history), $this->unsettled);
    }
}
