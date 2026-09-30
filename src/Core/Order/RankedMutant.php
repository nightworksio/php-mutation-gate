<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use NightWorksIO\MutationGate\Core\Mutant\MutantId;

/** A mutant, and the tests that killed it first, most kills first. */
final readonly class RankedMutant
{
    private function __construct(private MutantId $mutant, private Ranking $ranking)
    {
    }

    public static function of(MutantId $mutant, Ranking $ranking): self
    {
        return new self($mutant, $ranking);
    }

    public function mutant(): MutantId
    {
        return $this->mutant;
    }

    public function ranking(): Ranking
    {
        return $this->ranking;
    }
}
