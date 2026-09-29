<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Time\Day;

/** One mutant the config ignores, by the gate's id, with the reason and when the ignore ends (ADR-0008). */
final readonly class IgnoredMutant implements Ignored
{
    private function __construct(private MutantId $mutant, private string $reason, private Day|Absent $expires)
    {
    }

    public static function of(MutantId $mutant, string $reason, Day|Absent $expires): self
    {
        return new self($mutant, $reason, $expires);
    }

    public function mutant(): MutantId
    {
        return $this->mutant;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function expires(): Day|Absent
    {
        return $this->expires;
    }
}
