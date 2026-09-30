<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;

/** No ledger read holds a mutant the id or prefix names. */
final readonly class NoRecord
{
    private function __construct(private IdPrefix $sought)
    {
    }

    public static function of(IdPrefix $sought): self
    {
        return new self($sought);
    }

    public function sought(): IdPrefix
    {
        return $this->sought;
    }
}
