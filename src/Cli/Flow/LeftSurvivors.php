<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedIds;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

/**
 * What a round of static analysis's checks has left so far: the checks,
 * with each survivor left unchecked and why, and those survivors by why.
 *
 * @internal
 */
final readonly class LeftSurvivors
{
    private function __construct(public SurvivorChecks $checks, public UncheckedIds $unchecked)
    {
    }

    public static function none(): self
    {
        return new self(SurvivorChecks::none(), UncheckedIds::none());
    }

    /** These, with a survivor left unchecked. */
    public function leaving(UncheckedSurvivor $left, MutantId $survivor): self
    {
        return new self(
            $this->checks->leaving($left),
            $this->unchecked->with($left->why(), $survivor),
        );
    }

    /** These, with what the checks taught of one analyser. */
    public function timing(AnalyserHistory $history): self
    {
        return new self($this->checks->timing($history), $this->unchecked);
    }
}
