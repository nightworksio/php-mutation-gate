<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;

/** A mutant a removable test kills, and the kept test that kills it too. */
final readonly class KeptKill
{
    private function __construct(private MutantId $mutant, private TestName|TestId $keptBy)
    {
    }

    public static function of(MutantId $mutant, TestName|TestId $keptBy): self
    {
        return new self($mutant, $keptBy);
    }

    public function mutant(): MutantId
    {
        return $this->mutant;
    }

    public function keptBy(): TestName|TestId
    {
        return $this->keptBy;
    }
}
