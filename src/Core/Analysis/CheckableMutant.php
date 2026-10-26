<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;

/** A mutant offered to static analysis (ADR-0020): the mutant, and the mutant as its analyser reads it. */
interface CheckableMutant
{
    public function mutant(): Mutant;

    public function checkable(): Checkable;
}
