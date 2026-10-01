<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

/**
 * What a mutant a limit stopped comes to: one that ran out of memory as
 * memory triage judges it, and any other as timeout triage does, which
 * judges every mutant that did not time out as its status reports it.
 */
final readonly class MutantTriage
{
    private function __construct(private TimeoutTriage $timeouts, private MemoryTriage $memory)
    {
    }

    public static function under(TimeoutMode $timeouts): self
    {
        return new self(TimeoutTriage::under($timeouts), MemoryTriage::standard());
    }

    public function judged(Mutant $mutant): MutantJudgement
    {
        return $mutant->status() === MutantStatus::OutOfMemory
            ? $this->memory->judged($mutant)
            : $this->timeouts->judged($mutant);
    }
}
